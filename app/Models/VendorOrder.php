<?php

namespace App\Models;

use App\Enums\VendorOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'vendor_id', 'number', 'status', 'subtotal_cents', 'shipping_cents',
    'total_cents', 'commission_bps', 'commission_cents', 'vendor_earnings_cents',
    'refunded_cents', 'refund_id', 'carrier', 'tracking_number',
    'paid_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'cancel_reason',
])]
class VendorOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => VendorOrderStatus::class,
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
            'commission_cents' => 'integer',
            'vendor_earnings_cents' => 'integer',
            'refunded_cents' => 'integer',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
