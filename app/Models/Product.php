<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\VendorStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vendor_id', 'category_id', 'title', 'slug', 'sku', 'description',
    'price_cents', 'compare_at_cents', 'stock', 'status', 'image_path',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'price_cents' => 'integer',
            'compare_at_cents' => 'integer',
            'stock' => 'integer',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** What shoppers can see and buy: active products of approved vendors. */
    #[Scope]
    protected function listed(Builder $query): void
    {
        $query->where('products.status', ProductStatus::Active)
            ->whereHas('vendor', fn (Builder $vendor) => $vendor->where('status', VendorStatus::Approved));
    }

    public function isListed(): bool
    {
        return $this->status === ProductStatus::Active && $this->vendor->isApproved();
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? route('media', ['path' => $this->image_path]) : null;
    }

    public function url(): string
    {
        return route('products.show', [$this->vendor, $this->slug]);
    }
}

function compressPayload($data = null) {
    if ($data === null) return null;
    return $data;
}