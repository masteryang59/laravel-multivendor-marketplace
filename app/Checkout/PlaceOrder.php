<?php

namespace App\Checkout;

use App\Cart\CartLine;
use App\Cart\CartQuote;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\VendorOrderStatus;
use App\Enums\VendorStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into one Order (one payment) split into one VendorOrder per
 * vendor, and reserves the stock.
 *
 * - Idempotent: the checkout form carries a key; resubmitting it (double
 *   click, refresh, flaky network) returns the order the first submit made.
 * - What you saw is what you pay: the quote fingerprint from the checkout
 *   page must match a fresh quote, or nothing is placed.
 * - No overselling: each line is reserved with a conditional decrement
 *   (stock >= qty, same price, still active) inside the transaction; one
 *   failed line rolls back every reservation made before it.
 */
class PlaceOrder
{
    /** @param array<int, array{qty: int, price: int}> $cartItems */
    public function __invoke(User $user, array $cartItems, array $address, string $idempotencyKey, string $quoteHash): Order
    {
        if ($existing = $this->existing($user, $idempotencyKey)) {
            return $existing;
        }

        $quote = CartQuote::build($cartItems);
        if (! $quote->canCheckout()) {
            throw new CheckoutFailed(CheckoutFailed::NOT_PURCHASABLE, 'Some items in your cart can no longer be bought. Please review your cart.');
        }
        if (! hash_equals($quote->hash(), $quoteHash)) {
            throw new CheckoutFailed(CheckoutFailed::QUOTE_CHANGED, 'Prices, stock or shipping changed while you were checking out. Please review the updated totals.');
        }

        try {
            return DB::transaction(fn () => $this->place($user, $quote, $address, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent submit with the same key won the race.
            return $this->existing($user, $idempotencyKey) ?? throw $e;
        }
    }

    private function place(User $user, CartQuote $quote, array $address, string $idempotencyKey): Order
    {
        $vendorIds = array_map(fn ($g) => $g->vendor->id, $quote->groups());
        $approved = Vendor::whereKey($vendorIds)->where('status', VendorStatus::Approved)->count();
        if ($approved !== count($vendorIds)) {
            throw new CheckoutFailed(CheckoutFailed::NOT_PURCHASABLE, 'A shop in your cart has stopped selling. Please review your cart.');
        }

        $lines = array_merge(...array_map(fn ($g) => $g->lines, $quote->groups()));
        usort($lines, fn (CartLine $a, CartLine $b) => $a->productId <=> $b->productId); // stable lock order
        foreach ($lines as $line) {
            $reserved = Product::whereKey($line->productId)
                ->where('status', ProductStatus::Active)
                ->where('price_cents', $line->unitPriceCents)
                ->where('stock', '>=', $line->quantity)
                ->decrement('stock', $line->quantity);
            if ($reserved === 0) {
                throw new CheckoutFailed(CheckoutFailed::OUT_OF_STOCK, "Sorry, \"{$line->product->title}\" just sold out or changed. Please review your cart.");
            }
        }

        $order = Order::create([
            'number' => Order::newNumber(),
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'status' => OrderStatus::PendingPayment,
            'currency' => config('marketplace.currency'),
            'subtotal_cents' => $quote->subtotalCents(),
            'shipping_cents' => $quote->shippingCents(),
            'total_cents' => $quote->totalCents(),
            'ship_name' => $address['name'],
            'ship_line1' => $address['line1'],
            'ship_line2' => $address['line2'] ?? null,
            'ship_city' => $address['city'],
            'ship_postal_code' => $address['postal_code'],
            'ship_country' => strtoupper($address['country']),
            'expires_at' => now()->addMinutes(config('marketplace.reservation_minutes')),
        ]);

        foreach ($quote->groups() as $i => $group) {
            $vendor = $group->vendor;
            $subtotal = $group->subtotalCents();
            $commission = Vendor::commissionOn($subtotal, $vendor->commission_bps);
            $vendorOrder = $order->vendorOrders()->create([
                'vendor_id' => $vendor->id,
                'number' => $order->number.'-'.($i + 1),
                'status' => VendorOrderStatus::PendingPayment,
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $group->shippingCents(),
                'total_cents' => $group->totalCents(),
                'commission_bps' => $vendor->commission_bps,
                'commission_cents' => $commission,
                'vendor_earnings_cents' => $group->totalCents() - $commission,
            ]);
            foreach ($group->lines as $line) {
                $vendorOrder->items()->create([
                    'product_id' => $line->productId,
                    'title' => $line->product->title,
                    'sku' => $line->product->sku,
                    'unit_price_cents' => $line->unitPriceCents,
                    'quantity' => $line->quantity,
                    'line_total_cents' => $line->lineTotalCents(),
                ]);
            }
        }

        return $order;
    }

    private function existing(User $user, string $idempotencyKey): ?Order
    {
        return Order::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
    }
}
