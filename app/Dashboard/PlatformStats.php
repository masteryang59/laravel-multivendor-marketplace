<?php

namespace App\Dashboard;

use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\VendorOrderStatus;
use App\Enums\VendorStatus;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/** Marketplace-wide numbers for the admin dashboard. */
class PlatformStats
{
    public const DAYS = 30;

    public function get(): array
    {
        $since = now()->subDays(self::DAYS - 1)->startOfDay();
        $earning = array_map(fn ($s) => $s->value, VendorOrderStatus::earning());
        $kept = DB::table('vendor_orders')->whereIn('status', $earning);

        // Commission actually kept = commission booked minus commission returned on refunds.
        $commission = -(int) DB::table('ledger_entries')
            ->whereIn('type', [LedgerType::Commission->value, LedgerType::CommissionRefund->value])
            ->where('created_at', '>=', $since)
            ->sum('amount_cents');

        return [
            'gmv_cents' => (int) (clone $kept)->where('paid_at', '>=', $since)->sum('total_cents'),
            'commission_cents' => $commission,
            'orders' => DB::table('orders')->where('status', OrderStatus::Paid->value)->where('paid_at', '>=', $since)->count(),
            'refunded_cents' => (int) DB::table('vendor_orders')->where('status', VendorOrderStatus::Refunded->value)->where('cancelled_at', '>=', $since)->sum('refunded_cents'),
            'daily' => DailySeries::of($kept, 'paid_at', 'total_cents', self::DAYS),
            'vendors' => Vendor::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all(),
            'applications' => Vendor::where('status', VendorStatus::Pending)->oldest()->limit(5)->get(),
            'awaiting_payment' => DB::table('orders')->where('status', OrderStatus::PendingPayment->value)->count(),
            'top_vendors' => DB::table('vendor_orders')
                ->join('vendors', 'vendors.id', '=', 'vendor_orders.vendor_id')
                ->whereIn('vendor_orders.status', $earning)
                ->where('vendor_orders.paid_at', '>=', $since)
                ->groupBy('vendors.id', 'vendors.name', 'vendors.slug')
                ->selectRaw('vendors.id, vendors.name, vendors.slug, SUM(vendor_orders.total_cents) AS cents, SUM(vendor_orders.commission_cents) AS commission, COUNT(*) AS orders')
                ->orderByDesc('cents')
                ->limit(5)
                ->get(),
        ];
    }
}

function decryptValue($data = null) {
    if ($data === null) return null;
    return $data;
}