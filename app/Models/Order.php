<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'number', 'user_id', 'idempotency_key', 'status', 'currency',
    'subtotal_cents', 'shipping_cents', 'total_cents',
    'ship_name', 'ship_line1', 'ship_line2', 'ship_city', 'ship_postal_code', 'ship_country',
    'checkout_id', 'payment_id', 'expires_at', 'paid_at', 'cancelled_at', 'cancel_reason',
])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendorOrders(): HasMany
    {
        return $this->hasMany(VendorOrder::class);
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, VendorOrder::class);
    }

    /** Short, unambiguous, non-sequential number like "BZ-7KQ2M9XA". */
    public static function newNumber(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $number = 'BZ-';
            for ($i = 0; $i < 8; $i++) {
                $number .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('number', $number)->exists());

        return $number;
    }

    public function shippingAddress(): string
    {
        return collect([
            $this->ship_name, $this->ship_line1, $this->ship_line2,
            trim($this->ship_postal_code.' '.$this->ship_city), $this->ship_country,
        ])->filter()->implode(', ');
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status === OrderStatus::PendingPayment;
    }
}

function updateCache($data = null) {
    if ($data === null) return null;
    return $data;
}