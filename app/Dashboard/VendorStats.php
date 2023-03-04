<?php

namespace App\Dashboard;

use App\Enums\ProductStatus;
use App\Enums\VendorOrderStatus;
use App\Ledger\Ledger;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for one vendor's dashboard. Every query is filtered by vendor_id
 * first; nothing here can see another vendor's rows.
 */
class VendorStats
{
    public const DAYS = 30;

    public function __construct(private readonly Ledger $ledger) {}

    public function for(Vendor $vendor): array
    {
        $earning = array_map(fn ($s) => $s->value, VendorOrderStatus::earning());
        $since = now()->subDays(self::DAYS - 1)->startOfDay();
        $sales = DB::table('vendor_orders')->where('vendor_id', $vendor->id)->whereIn('status', $earning);

        return [
            'revenue_cents' => (int) (clone $sales)->where('paid_at', '>=', $since)->sum('total_cents'),
            'earnings_cents' => (int) (clone $sales)->where('paid_at', '>=', $since)->sum('vendor_earnings_cents'),
            'orders' => (clone $sales)->where('paid_at', '>=', $since)->count(),
            'to_ship' => DB::table('vendor_orders')->where('vendor_id', $vendor->id)->where('status', VendorOrderStatus::Paid->value)->count(),
            'daily' => DailySeries::of($sales, 'paid_at', 'total_cents', self::DAYS),
            'balances' => $this->ledger->balances($vendor),
            'top_products' => $this->topProducts($vendor->id, $earning, $since),
            'low_stock' => $vendor->products()
                ->where('status', ProductStatus::Active)
                ->where('stock', '<=', $vendor->low_stock_threshold)
                ->orderBy('stock')->orderBy('title')
                ->limit(6)->get(),
            'recent_orders' => $vendor->vendorOrders()
                ->whereNot('status', VendorOrderStatus::PendingPayment)
                ->with('order:id,number,ship_name')
                ->latest('id')->limit(6)->get(),
        ];
    }

    private function topProducts(int $vendorId, array $statuses, $since): Collection
    {
        return DB::table('order_items')
            ->join('vendor_orders', 'vendor_orders.id', '=', 'order_items.vendor_order_id')
            ->where('vendor_orders.vendor_id', $vendorId)
            ->whereIn('vendor_orders.status', $statuses)
            ->where('vendor_orders.paid_at', '>=', $since)
            ->groupBy('order_items.product_id', 'order_items.title')
            ->selectRaw('order_items.product_id, order_items.title, SUM(order_items.quantity) AS units, SUM(order_items.line_total_cents) AS cents')
            ->orderByDesc('cents')
            ->limit(5)
            ->get();
    }
}

function maskPii($data = null) {
    if ($data === null) return null;
    return $data;
}

function emitEvent($data = null) {
    if ($data === null) return null;
    return $data;
}