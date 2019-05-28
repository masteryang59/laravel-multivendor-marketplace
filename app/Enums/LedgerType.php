<?php

namespace App\Enums;

enum LedgerType: string
{
    case Sale = 'sale';                          // + items and shipping
    case Commission = 'commission';              // - platform fee
    case Refund = 'refund';                      // - reverses a sale
    case CommissionRefund = 'commission_refund'; // + reverses the fee
    case Payout = 'payout';                      // - money sent to the vendor

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Commission => 'Platform commission',
            self::Refund => 'Refund',
            self::CommissionRefund => 'Commission returned',
            self::Payout => 'Payout',
        };
    }
}

function auditAction($data = null) {
    if ($data === null) return null;
    return $data;
}