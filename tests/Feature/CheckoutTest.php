<?php

namespace Tests\Feature;

use App\Cart\CartQuote;
use App\Checkout\CheckoutFailed;
use App\Checkout\PlaceOrder;
use App\Enums\OrderStatus;
use App\Enums\VendorOrderStatus;
use App\Enums\VendorStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    public function test_one_order_is_split_into_one_part_per_vendor(): void
    {
        $mugs = $this->vendor(['shipping_flat_cents' => 650, 'free_shipping_over_cents' => 7500, 'commission_bps' => 1000]);
        $coffee = $this->vendor(['shipping_flat_cents' => 450, 'free_shipping_over_cents' => 4000, 'commission_bps' => 800]);
        $mug = $this->product($mugs, ['price_cents' => 2800, 'stock' => 5]);
        $bowl = $this->product($mugs, ['price_cents' => 4200, 'stock' => 5]);
        $beans = $this->product($coffee, ['price_cents' => 1900, 'stock' => 5]);

        $order = $this->placeOrder($this->customer(), [$mug->id => 2, $bowl->id => 1, $beans->id => 1]);

        // Mugs: 2x28 + 42 = 98.00, over the 75.00 threshold -> free shipping.
        // Coffee: 19.00 + 4.50 shipping.
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(9800 + 1900, $order->subtotal_cents);
        $this->assertSame(450, $order->shipping_cents);
        $this->assertSame(12150, $order->total_cents);

        [$a, $b] = $order->vendorOrders()->orderBy('id')->get()->all();
        $this->assertSame([$mugs->id, 9800, 0, 9800, 1000, 980, 8820], [$a->vendor_id, $a->subtotal_cents, $a->shipping_cents, $a->total_cents, $a->commission_bps, $a->commission_cents, $a->vendor_earnings_cents]);
        $this->assertSame([$coffee->id, 1900, 450, 2350, 800, 152, 2198], [$b->vendor_id, $b->subtotal_cents, $b->shipping_cents, $b->total_cents, $b->commission_bps, $b->commission_cents, $b->vendor_earnings_cents]);
        $this->assertSame($order->number.'-1', $a->number);
        $this->assertSame(VendorOrderStatus::PendingPayment, $a->status);
        $this->assertSame(2, $a->items()->where('product_id', $mug->id)->value('quantity'));

        // Stock is reserved at once.
        $this->assertSame([3, 4, 4], [$mug->fresh()->stock, $bowl->fresh()->stock, $beans->fresh()->stock]);
        $this->assertTrue($order->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));
    }

    public function test_checkout_page_posts_to_fakepay_and_clears_the_cart(): void
    {
        $product = $this->product($this->vendor(), ['price_cents' => 1000, 'stock' => 3]);
        $customer = $this->customer();
        $this->actingAs($customer)->post('/cart/items', ['product_id' => $product->id, 'quantity' => 2]);

        $page = $this->get('/checkout')->assertOk()->assertSee('Continue to payment');
        preg_match('/name="quote_hash" value="([0-9a-f]{64})"/', $page->getContent(), $hash);
        preg_match('/name="idempotency_key" value="([^"]{40})"/', $page->getContent(), $key);

        $response = $this->post('/checkout', $this->address() + ['quote_hash' => $hash[1], 'idempotency_key' => $key[1]]);

        $order = Order::sole();
        $response->assertRedirect(route('fakepay.checkout', $order->checkout_id));
        $this->assertSame(2000 + 500, $order->total_cents);
        $this->assertSame([], session('cart.items', []));
        $this->get(route('fakepay.checkout', $order->checkout_id))->assertOk()->assertSee('$25.00');
    }

    public function test_resubmitting_the_same_checkout_returns_the_same_order(): void
    {
        $product = $this->product($this->vendor(), ['stock' => 5]);
        $customer = $this->customer();
        $items = $this->items([$product->id => 1]);
        $hash = CartQuote::build($items)->hash();
        $key = Str::random(40);

        $first = app(PlaceOrder::class)($customer, $items, $this->address(), $key, $hash);
        $second = app(PlaceOrder::class)($customer, $items, $this->address(), $key, $hash);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Order::count());
        $this->assertSame(4, $product->fresh()->stock, 'stock reserved once');
    }

    public function test_a_price_change_after_the_page_was_shown_places_nothing(): void
    {
        $product = $this->product($this->vendor(), ['price_cents' => 1000, 'stock' => 5]);
        $items = $this->items([$product->id => 1]);
        $shownHash = CartQuote::build($items)->hash();
        $product->update(['price_cents' => 1200]);

        try {
            app(PlaceOrder::class)($this->customer(), $items, $this->address(), Str::random(40), $shownHash);
            $this->fail('expected CheckoutFailed');
        } catch (CheckoutFailed $e) {
            $this->assertSame(CheckoutFailed::QUOTE_CHANGED, $e->reason);
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_the_last_unit_goes_to_exactly_one_buyer(): void
    {
        $product = $this->product($this->vendor(), ['stock' => 1]);
        $items = $this->items([$product->id => 1]);
        $hash = CartQuote::build($items)->hash();

        app(PlaceOrder::class)($this->customer(), $items, $this->address(), Str::random(40), $hash);

        // Second buyer had the same quote on screen (stock was 1 when they loaded it).
        try {
            app(PlaceOrder::class)($this->customer(), $items, $this->address(), Str::random(40), $hash);
            $this->fail('expected CheckoutFailed');
        } catch (CheckoutFailed $e) {
            $this->assertContains($e->reason, [CheckoutFailed::NOT_PURCHASABLE, CheckoutFailed::OUT_OF_STOCK]);
        }
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, Order::count());
    }

    public function test_the_conditional_reservation_refuses_when_stock_ran_out_after_quoting(): void
    {
        // Simulates the race window between building the quote and reserving:
        // the quote said 2 were available, another checkout took one meanwhile.
        $vendor = $this->vendor();
        $first = $this->product($vendor, ['stock' => 5]);
        $second = $this->product($vendor, ['stock' => 2]);
        $items = $this->items([$first->id => 1, $second->id => 2]);
        $hash = CartQuote::build($items)->hash();

        // Right after the first line is reserved, "someone else" buys one of
        // the second product. (It runs on the same connection, so the
        // rollback below undoes it too; what matters is that the guard
        // refuses and the first reservation is not left behind.)
        $raced = false;
        DB::listen(function ($query) use (&$raced, $second) {
            if (! $raced && str_starts_with($query->sql, 'update "products"')) {
                $raced = true;
                DB::table('products')->where('id', $second->id)->decrement('stock');
            }
        });

        try {
            app(PlaceOrder::class)($this->customer(), $items, $this->address(), Str::random(40), $hash);
            $this->fail('expected CheckoutFailed');
        } catch (CheckoutFailed $e) {
            $this->assertSame(CheckoutFailed::OUT_OF_STOCK, $e->reason);
        }
        $this->assertTrue($raced);
        $this->assertSame(5, $first->fresh()->stock, 'first reservation rolled back');
        $this->assertSame(0, Order::count());
    }

    public function test_a_vendor_suspended_mid_checkout_blocks_the_order(): void
    {
        $vendor = $this->vendor();
        $product = $this->product($vendor);
        $items = $this->items([$product->id => 1]);
        $hash = CartQuote::build($items)->hash();
        $vendor->update(['status' => VendorStatus::Suspended]);

        $this->expectException(CheckoutFailed::class);
        app(PlaceOrder::class)($this->customer(), $items, $this->address(), Str::random(40), $hash);
    }

    public function test_checkout_needs_an_account_and_a_valid_address(): void
    {
        $this->get('/checkout')->assertRedirect('/login');

        $product = $this->product($this->vendor());
        $this->actingAs($this->customer())->post('/cart/items', ['product_id' => $product->id]);
        $this->post('/checkout', ['quote_hash' => str_repeat('a', 64), 'idempotency_key' => Str::random(40), 'country' => 'USA'])
            ->assertSessionHasErrors(['name', 'line1', 'city', 'postal_code', 'country']);
        $this->assertSame(0, Order::count());
    }

    public function test_a_stale_quote_on_the_form_sends_the_shopper_back_to_review(): void
    {
        $product = $this->product($this->vendor(), ['price_cents' => 1000]);
        $this->actingAs($this->customer())->post('/cart/items', ['product_id' => $product->id]);

        $this->post('/checkout', $this->address() + ['quote_hash' => str_repeat('0', 64), 'idempotency_key' => Str::random(40)])
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }
}
