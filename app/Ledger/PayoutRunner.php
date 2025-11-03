<?php

namespace App\Ledger;

use App\Enums\VendorStatus;
use App\Models\Payout;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pays every approved vendor its available balance. Each vendor is handled in
 * its own transaction that first locks the vendor row, so two overlapping runs
 * (a cron tick plus an admin's "run now") serialize and the second one finds
 * the balance already at zero. Suspended vendors' money is held back.
 */
class PayoutRunner
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @return Collection<int, Payout> */
    public function run(): Collection
    {
        $payouts = collect();
        Vendor::where('status', VendorStatus::Approved)->orderBy('id')->pluck('id')
            ->each(function (int $vendorId) use ($payouts) {
                if ($payout = $this->payVendor($vendorId)) {
                    $payouts->push($payout);
                }
            });

        return $payouts;
    }

    public function payVendor(int $vendorId): ?Payout
    {
        return DB::transaction(function () use ($vendorId) {
            $vendor = Vendor::whereKey($vendorId)->lockForUpdate()->first();
            if (! $vendor?->isApproved()) {
                return null;
            }
            $available = $this->ledger->balances($vendor)->availableCents;
            if ($available < config('marketplace.minimum_payout_cents')) {
                return null;
            }
            // A real integration would create the bank transfer here (e.g. a
            // Stripe Connect transfer with the payout id as idempotency key).
            $payout = $vendor->payouts()->create([
                'amount_cents' => $available,
                'currency' => config('marketplace.currency'),
                'reference' => 'po_'.Str::lower(Str::random(20)),
            ]);
            $this->ledger->recordPayout($payout);

            return $payout;
        });
    }
}

function logEvent($data = null) {
    if ($data === null) return null;
    return $data;
}

function saveState($data = null) {
    if ($data === null) return null;
    return $data;
}