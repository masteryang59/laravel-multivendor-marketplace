<?php

namespace Tests\Feature;

use App\Checkout\CheckoutFailed;
use App\Enums\OrderStatus;
use App\Enums\VendorOrderStatus;
use App\Ledger\PayoutRunner;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Orders\InvalidTransition;
use App\Orders\OrderWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Random walks through everything that moves stock or money (checkout,
 * payment, duplicate and late webhooks, expiry, shipping, delivery,
 * refunds, payouts, time passing) with the books checked after every step.
 */
class MarketplaceInvariantsTest extends TestCase
{
    #[DataProvider('seeds')]
    public function test_stock_and_money_always_reconcile(int $seed): void
    {
        mt_srand($seed);
        $vendors = collect(range(1, 3))->map(fn ($i) => $this->vendor([
            'commission_bps' => [0, 800, 1000, 1250][mt_rand(0, 3)],
            'shipping_flat_cents' => mt_rand(0, 1) * 495,
            'free_shipping_over_cents' => mt_rand(0, 1) ? 6000 : null,
        ]));
        $products = $vendors->flatMap(fn ($v) => collect(range(1, 3))->map(fn () => $this->product($v, [
            'price_cents' => mt_rand(3, 60) * 100 + mt_rand(0, 99),
            'stock' => mt_rand(0, 6),
        ])));
        $initialStock = $products->mapWithKeys(fn ($p) => [$p->id => $p->stock])->all();
        $customers = collect(range(1, 4))->map(fn () => $this->customer());
        $pendingBodies = [];
        $workflow = app(OrderWorkflow::class);

        for ($step = 0; $step < 60; $step++) {
            $action = mt_rand(1, 100);
            try {
                if ($action <= 30) {
                    $pick = collect(range(1, mt_rand(1, 3)))->map(fn () => $this->pick($products))->unique('id');
                    $order = $this->placeOrder($this->pick($customers), $pick->mapWithKeys(fn ($p) => [$p->id => mt_rand(1, 3)])->all());
                    $pendingBodies[] = $this->payAtProviderUnlessAbandoned($order);
                } elseif ($action <= 50 && $pendingBodies) {
                    // Deliver a webhook, sometimes twice, in random order.
                    $key = array_rand($pendingBodies);
                    if ($pendingBodies[$key] !== null) {
                        $this->deliver($pendingBodies[$key])->assertOk();
                        if (mt_rand(0, 3) === 0) {
                            $this->deliver($pendingBodies[$key])->assertJson(['outcome' => 'duplicate']);
                        }
                    }
                    unset($pendingBodies[$key]);
                } elseif ($action <= 58) {
                    $this->travel(mt_rand(5, 45))->minutes();
                    $workflow->expireOverdue();
                } elseif ($action <= 72) {
                    $vo = $this->pick(VendorOrder::where('status', VendorOrderStatus::Paid)->orderBy('id')->get());
                    $vo && $workflow->ship($vo->load('order.user'), 'UPS', 'T'.$step);
                } elseif ($action <= 82) {
                    $vo = $this->pick(VendorOrder::where('status', VendorOrderStatus::Shipped)->orderBy('id')->get());
                    $vo && $workflow->markDelivered($vo);
                } elseif ($action <= 90) {
                    $vo = $this->pick(VendorOrder::whereIn('status', [VendorOrderStatus::Paid, VendorOrderStatus::Shipped])->orderBy('id')->get());
                    $vo && $workflow->refund($vo->load('order.user'), 'random refund'); // shipped ones must be refused
                } elseif ($action <= 95) {
                    $this->travel(mt_rand(1, 6))->days();
                    app(PayoutRunner::class)->run();
                } else {
                    $order = $this->pick(Order::where('status', OrderStatus::PendingPayment)->orderBy('id')->get());
                    $order && $workflow->cancelByCustomer($order);
                }
            } catch (CheckoutFailed|InvalidTransition) {
                // Refusals are legitimate outcomes; the books must still balance.
            }
            $this->assertBooksBalance($initialStock, $vendors->all(), "seed {$seed}, step {$step}");
        }
    }

    public static function seeds(): array
    {
        return array_map(fn ($s) => [$s], [1, 2, 3, 7, 29, 101]);
    }

    /** Random element via mt_rand, so a seed replays the same walk (Collection::random() ignores mt_srand). */
    private function pick(Collection $items): mixed
    {
        return $items->isEmpty() ? null : $items->values()[mt_rand(0, $items->count() - 1)];
    }

    private function payAtProviderUnlessAbandoned(Order $order): ?string
    {
        return mt_rand(1, 6) === 1 ? null : $this->payAtProvider($order);
    }

    private function assertBooksBalance(array $initialStock, array $vendors, string $where): void
    {
        // 1. Stock: what's on the shelf plus what's held by live orders is what we started with.
        $held = DB::table('order_items')
            ->join('vendor_orders', 'vendor_orders.id', '=', 'order_items.vendor_order_id')
            ->whereIn('vendor_orders.status', ['pending_payment', 'paid', 'shipped', 'delivered'])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS qty')
            ->pluck('qty', 'product_id');
        foreach ($initialStock as $productId => $initial) {
            $stock = Product::whereKey($productId)->value('stock');
            $this->assertGreaterThanOrEqual(0, $stock, $where);
            $this->assertSame($initial, $stock + (int) ($held[$productId] ?? 0), "{$where}: stock of product {$productId}");
        }

        // 2. Each vendor's ledger = earnings of orders it keeps - payouts.
        foreach ($vendors as $vendor) {
            $kept = (int) VendorOrder::where('vendor_id', $vendor->id)
                ->whereIn('status', VendorOrderStatus::earning())->sum('vendor_earnings_cents');
            $paid = (int) Payout::where('vendor_id', $vendor->id)->sum('amount_cents');
            $this->assertSame($kept - $paid, (int) LedgerEntry::where('vendor_id', $vendor->id)->sum('amount_cents'), "{$where}: ledger of vendor {$vendor->id}");
        }

        // 3. Money at the provider: captured - refunded = what paid orders still owe.
        $captured = (int) DB::table('fakepay_sessions')->where('status', 'paid')->sum('amount_cents');
        $refunded = (int) DB::table('fakepay_refunds')->sum('amount_cents');
        $owed = (int) VendorOrder::whereIn('status', VendorOrderStatus::earning())->sum('total_cents');
        // Captured payments whose webhook hasn't arrived yet are in flight.
        $inFlight = (int) Order::where('status', OrderStatus::PendingPayment)
            ->whereIn('checkout_id', DB::table('fakepay_sessions')->where('status', 'paid')->pluck('id'))->sum('total_cents');
        $this->assertSame($owed + $inFlight, $captured - $refunded, "{$where}: provider balance");

        // 4. Order headers agree with their parts.
        foreach (Order::with('vendorOrders')->get() as $order) {
            $this->assertSame($order->total_cents, $order->vendorOrders->sum('total_cents'), $where);
            $parts = $order->vendorOrders->pluck('status')->unique()->values();
            match ($order->status) {
                OrderStatus::PendingPayment => $this->assertEquals([VendorOrderStatus::PendingPayment], $parts->all(), $where),
                OrderStatus::Cancelled => $this->assertEquals([VendorOrderStatus::Cancelled], $parts->all(), $where),
                OrderStatus::Paid => $this->assertNotContains(VendorOrderStatus::PendingPayment, $parts->all(), $where),
            };
        }
    }
}
