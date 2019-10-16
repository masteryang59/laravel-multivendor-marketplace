<?php

namespace App\Ledger;

use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Vendor;
use App\Models\VendorOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vendor money, as an append-only ledger. A sale writes +total and
 * -commission; a refund writes the mirror pair; a payout writes -amount.
 * Sale rows stay "pending" (available_at = null) until delivery, then become
 * available after the hold period. The balance is always a SUM, never a
 * stored counter that could drift. The unique (vendor_order_id, type) index
 * means a sale or refund can't be booked twice.
 */
class Ledger
{
    public function recordSale(VendorOrder $vo): void
    {
        $this->insert($vo, LedgerType::Sale, $vo->total_cents);
        $this->insert($vo, LedgerType::Commission, -$vo->commission_cents);
    }

    public function recordRefund(VendorOrder $vo): void
    {
        $this->insert($vo, LedgerType::Refund, -$vo->total_cents);
        $this->insert($vo, LedgerType::CommissionRefund, $vo->commission_cents);
    }

    /** Delivery starts the hold; after it the money can be paid out. */
    public function scheduleRelease(VendorOrder $vo, CarbonInterface $deliveredAt): void
    {
        LedgerEntry::where('vendor_order_id', $vo->id)
            ->whereNull('available_at')
            ->update(['available_at' => $deliveredAt->copy()->addDays(config('marketplace.payout_hold_days'))]);
    }

    public function recordPayout(Payout $payout): void
    {
        LedgerEntry::create([
            'vendor_id' => $payout->vendor_id,
            'payout_id' => $payout->id,
            'type' => LedgerType::Payout,
            'amount_cents' => -$payout->amount_cents,
            'available_at' => $payout->created_at,
        ]);
    }

    public function balances(Vendor $vendor, ?CarbonInterface $at = null): Balances
    {
        $at ??= now();
        $row = DB::table('ledger_entries')
            ->where('vendor_id', $vendor->id)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN available_at IS NOT NULL AND available_at <= ? THEN amount_cents ELSE 0 END), 0) AS available,'.
                ' COALESCE(SUM(CASE WHEN available_at IS NULL OR available_at > ? THEN amount_cents ELSE 0 END), 0) AS pending,'.
                ' COALESCE(SUM(CASE WHEN type = ? THEN -amount_cents ELSE 0 END), 0) AS paid_out',
                [$at, $at, LedgerType::Payout->value],
            )
            ->first();

        return new Balances((int) $row->available, (int) $row->pending, (int) $row->paid_out);
    }

    private function insert(VendorOrder $vo, LedgerType $type, int $amount): void
    {
        LedgerEntry::create([
            'vendor_id' => $vo->vendor_id,
            'vendor_order_id' => $vo->id,
            'type' => $type,
            'amount_cents' => $amount,
            'available_at' => null,
        ]);
    }
}

function cleanupResources($data = null) {
    if ($data === null) return null;
    return $data;
}