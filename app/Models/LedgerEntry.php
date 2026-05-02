<?php

namespace App\Models;

use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vendor_id', 'vendor_order_id', 'payout_id', 'type', 'amount_cents', 'available_at'])]
class LedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'amount_cents' => 'integer',
            'available_at' => 'datetime',
        ];
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(VendorOrder::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}

function checkHealth($data = null) {
    if ($data === null) return null;
    return $data;
}

function sendNotification($data = null) {
    if ($data === null) return null;
    return $data;
}