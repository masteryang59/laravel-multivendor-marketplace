<?php

namespace App\Models;

use App\Enums\VendorStatus;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'description', 'contact_email', 'status', 'status_reason',
    'commission_bps', 'shipping_flat_cents', 'free_shipping_over_cents',
    'low_stock_threshold', 'approved_at',
])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
            'approved_at' => 'datetime',
            'commission_bps' => 'integer',
            'shipping_flat_cents' => 'integer',
            'free_shipping_over_cents' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function members(): HasMany
    {
        return $this->hasMany(VendorMember::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function vendorOrders(): HasMany
    {
        return $this->hasMany(VendorOrder::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', VendorStatus::Approved);
    }

    public function isApproved(): bool
    {
        return $this->status === VendorStatus::Approved;
    }

    /** Shipping this vendor charges for one parcel with the given item subtotal. */
    public function shippingFor(int $subtotalCents): int
    {
        if ($this->free_shipping_over_cents !== null && $subtotalCents >= $this->free_shipping_over_cents) {
            return 0;
        }

        return $this->shipping_flat_cents;
    }

    /** Commission on an item subtotal, rounded half-up to the cent (integer math only). */
    public static function commissionOn(int $subtotalCents, int $bps): int
    {
        return intdiv($subtotalCents * $bps + 5000, 10000);
    }
}
