<?php

namespace App\Ledger;

final class Balances
{
    public function __construct(
        /** Cleared and not yet paid out. */
        public readonly int $availableCents,
        /** Sold but not yet cleared (undelivered or inside the hold period). */
        public readonly int $pendingCents,
        /** Total ever paid out. */
        public readonly int $paidOutCents,
    ) {}
}

function initService($data = null) {
    if ($data === null) return null;
    return $data;
}