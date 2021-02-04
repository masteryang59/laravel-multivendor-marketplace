<?php

namespace Tests\Feature;

use App\Checkout\StartPayment;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\VendorOrderStatus;
use App\Mail\VendorOrderRefunded;
use App\Mail\VendorOrderShipped;
use App\Models\LedgerEntry;
use App\Orders\InvalidTransition;
use App\Orders\OrderWorkflow;
use App\Payments\FakePay\FakePay;
use App\Payments\PaymentFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    public function test_vendor_ships_then_delivers_and_the_hold_period_starts(): void
    {
        $vendor = $this->vendor();
        $customer = $this->customer();
        $order = $this->paidOrder($customer, [$this->product($vendor)->id => 1]);
        $vo = $order->vendorOrders->first();

        $this->actingAs($this->owner($vendor))
            ->post(route('vendor.orders.ship', $vo), ['carrier' => 'UPS', 'tracking_number' => '1Z 999 AA1'])
            ->assertSessionHas('status');
        $vo->refresh();
        $this->assertSame(VendorOrderStatus::Shipped, $vo->status);
        $this->assertSame('1Z 999 AA1', $vo->tracking_number);
        Mail::assertQueued(VendorOrderShipped::class, fn ($m) => $m->hasTo($customer->email));

        $this->post(route('vendor.orders.deliver', $vo))->assertSessionHas('status');
        $this->assertSame(VendorOrderStatus::Delivered, $vo->fresh()->status);
        $expected = now()->addDays(config('marketplace.payout_hold_days'))->toDateString();
        $this->assertSame([$expected, $expected], LedgerEntry::pluck('available_at')->map->toDateString()->all());
    }

    public function test_stale_buttons_do_not_change_anything_twice(): void
    {
        $vendor = $this->vendor();
        $vo = $this->paidOrder($this->customer(), [$this->product($vendor)->id => 1])->vendorOrders->first();
        $this->actingAs($this->owner($vendor));

        $this->post(route('vendor.orders.ship', $vo), ['carrier' => 'UPS', 'tracking_number' => 'A1']);
        $this->from(route('vendor.orders.show', $vo))
            ->post(route('vendor.orders.ship', $vo), ['carrier' => 'DHL', 'tracking_number' => 'B2'])
            ->assertRedirect(route('vendor.orders.show', $vo))
            ->assertSessionHas('error');
        $this->post(route('vendor.orders.refund', $vo), ['reason' => 'Changed my mind'])->assertSessionHas('error');

        $this->assertSame('A1', $vo->fresh()->tracking_number);
        $this->assertSame(0, DB::table('fakepay_refunds')->count());
    }

    public function test_concurrent_transitions_only_one_wins(): void
    {
        $vo = $this->paidOrder($this->customer(), [$this->product($this->vendor())->id => 1])->vendorOrders->first();
        $stale = $vo->fresh(['order']);
        app(OrderWorkflow::class)->refund($vo->fresh(['order']), 'Out of stock');

        // The second request loaded the order before the first one committed.
        $this->expectException(InvalidTransition::class);
        try {
            app(OrderWorkflow::class)->refund($stale, 'Out of stock');
        } finally {
            $this->assertSame(1, DB::table('fakepay_refunds')->count());
            $this->assertSame(2, LedgerEntry::where('type', LedgerType::Refund)->orWhere('type', LedgerType::CommissionRefund)->count());
        }
    }

    public function test_vendor_refund_returns_only_its_part_and_restocks(): void
    {
        $a = $this->vendor(['shipping_flat_cents' => 500]);
        $b = $this->vendor(['shipping_flat_cents' => 300]);
        $pa = $this->product($a, ['price_cents' => 2000, 'stock' => 5]);
        $pb = $this->product($b, ['price_cents' => 1000, 'stock' => 5]);
        $customer = $this->customer();
        $order = $this->paidOrder($customer, [$pa->id => 2, $pb->id => 1]);
        $voA = $order->vendorOrders->firstWhere('vendor_id', $a->id);

        $this->actingAs($this->owner($a))
            ->post(route('vendor.orders.refund', $voA), ['reason' => 'Kiln accident'])
            ->assertSessionHas('status');

        $voA->refresh();
        $this->assertSame(VendorOrderStatus::Refunded, $voA->status);
        $this->assertSame(4500, $voA->refunded_cents);
        $this->assertSame(5, $pa->fresh()->stock);
        $this->assertSame(4, $pb->fresh()->stock);
        $this->assertSame(VendorOrderStatus::Paid, $order->vendorOrders->firstWhere('vendor_id', $b->id)->fresh()->status);

        $refund = DB::table('fakepay_refunds')->sole();
        $this->assertSame([$order->payment_id, 4500, "refund-{$voA->number}"], [$refund->payment_id, (int) $refund->amount_cents, $refund->idempotency_key]);
        $this->assertSame(0, (int) LedgerEntry::where('vendor_id', $a->id)->sum('amount_cents'), 'sale fully reversed');
        Mail::assertQueued(VendorOrderRefunded::class, fn ($m) => $m->hasTo($customer->email));
    }

    public function test_customer_can_cancel_one_shops_part_before_it_ships(): void
    {
        $a = $this->vendor();
        $b = $this->vendor();
        $customer = $this->customer();
        $order = $this->paidOrder($customer, [$this->product($a)->id => 1, $this->product($b)->id => 1]);
        [$voA, $voB] = $order->vendorOrders->sortBy('id')->values()->all();
        app(OrderWorkflow::class)->ship($voB->load('order.user'), 'UPS', 'X1');

        $this->actingAs($customer)->post(route('orders.parts.cancel', [$order, $voA]))->assertSessionHas('status');
        $this->post(route('orders.parts.cancel', [$order, $voB]))->assertSessionHas('error');

        $this->assertSame(VendorOrderStatus::Refunded, $voA->fresh()->status);
        $this->assertSame(VendorOrderStatus::Shipped, $voB->fresh()->status);
    }

    public function test_customers_only_see_and_touch_their_own_orders(): void
    {
        $order = $this->paidOrder($this->customer(), [$this->product($this->vendor())->id => 1]);
        $other = $this->customer();

        $this->actingAs($other)->get(route('orders.show', $order))->assertNotFound();
        $this->post(route('orders.cancel', $order))->assertNotFound();
        $this->post(route('orders.parts.cancel', [$order, $order->vendorOrders->first()]))->assertNotFound();
    }

    public function test_a_part_must_belong_to_the_order_in_the_url(): void
    {
        $customer = $this->customer();
        $mine = $this->paidOrder($customer, [$this->product($this->vendor())->id => 1]);
        $theirs = $this->paidOrder($this->customer(), [$this->product($this->vendor())->id => 1]);

        $this->actingAs($customer)
            ->post(route('orders.parts.cancel', [$mine, $theirs->vendorOrders->first()]))
            ->assertNotFound();
        $this->assertSame(VendorOrderStatus::Paid, $theirs->vendorOrders->first()->fresh()->status);
    }

    public function test_unpaid_orders_can_be_cancelled_and_expire_on_their_own(): void
    {
        $product = $this->product($this->vendor(), ['stock' => 5]);
        $customer = $this->customer();
        $cancelled = $this->placeOrder($customer, [$product->id => 2]);
        $expiring = $this->placeOrder($customer, [$product->id => 1]);
        $this->assertSame(2, $product->fresh()->stock);

        $this->actingAs($customer)->post(route('orders.cancel', $cancelled))->assertSessionHas('status');
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('expired', app(FakePay::class)->session($cancelled->checkout_id)->status);
        $this->assertSame(4, $product->fresh()->stock);

        $this->travel(29)->minutes();
        $this->artisan('marketplace:expire-reservations');
        $this->assertSame(OrderStatus::PendingPayment, $expiring->fresh()->status);
        $this->travel(2)->minutes();
        $this->artisan('marketplace:expire-reservations');
        $this->assertSame(OrderStatus::Cancelled, $expiring->fresh()->status);
        $this->assertSame('Payment window expired', $expiring->fresh()->cancel_reason);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_cancelling_an_order_that_was_just_paid_at_the_provider_is_refused(): void
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, [$this->product($this->vendor())->id => 1]);
        $this->payAtProvider($order); // webhook not here yet

        $this->actingAs($customer)->from(route('orders.show', $order))
            ->post(route('orders.cancel', $order))
            ->assertSessionHas('error');
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_pay_now_reuses_the_open_checkout_and_never_opens_a_second(): void
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, [$this->product($this->vendor())->id => 1]);
        $url = route('fakepay.checkout', $order->checkout_id);

        $this->actingAs($customer)->post(route('orders.pay', $order))->assertRedirect($url);
        $this->post(route('orders.pay', $order))->assertRedirect($url);
        $this->assertSame(1, DB::table('fakepay_sessions')->count());

        $this->payAtProvider($order);
        $this->post(route('orders.pay', $order))->assertRedirect(route('orders.show', $order));
        $this->assertSame(1, DB::table('fakepay_sessions')->count());
        $this->expectException(InvalidTransition::class);
        app(StartPayment::class)($order->fresh());
    }

    public function test_the_order_page_waits_for_the_webhook_after_returning_from_fakepay(): void
    {
        $customer = $this->customer();
        $order = $this->placeOrder($customer, [$this->product($this->vendor())->id => 1]);
        $body = $this->payAtProvider($order);

        $this->actingAs($customer)->get(route('orders.show', $order).'?returned=1')
            ->assertSee('Confirming your payment')
            ->assertSee('http-equiv="refresh"', false);

        $this->deliver($body);
        $this->get(route('orders.show', $order).'?returned=1')
            ->assertDontSee('Confirming your payment')
            ->assertSee('To ship');
    }

    public function test_fakepay_page_refuses_to_take_a_payment_twice(): void
    {
        $order = $this->placeOrder($this->customer(), [$this->product($this->vendor())->id => 1]);
        $this->payAtProvider($order);

        $this->post(route('fakepay.pay', $order->checkout_id))->assertRedirect(route('fakepay.checkout', $order->checkout_id));
        $this->get(route('fakepay.checkout', $order->checkout_id))->assertSee('already paid');
    }

    public function test_fakepay_refunds_are_idempotent_and_capped(): void
    {
        $order = $this->paidOrder($this->customer(), [$this->product($this->vendor(), ['price_cents' => 1000])->id => 1]);
        $fakePay = app(FakePay::class);

        $first = $fakePay->refund($order->payment_id, 600, 'key-1');
        $this->assertSame($first, $fakePay->refund($order->payment_id, 600, 'key-1'));
        $this->expectException(PaymentFailed::class);
        $fakePay->refund($order->payment_id, $order->total_cents, 'key-2'); // more than what's left
    }
}
