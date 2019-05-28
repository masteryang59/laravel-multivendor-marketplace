<?php

namespace App\Cart;

use App\Models\Product;

final class CartLine
{
    public function __construct(
        public readonly int $productId,
        public readonly ?Product $product,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        /** Price stored in the cart when it differs from the current price. */
        public readonly ?int $previousPriceCents,
        /** Why this line can't be bought right now, if it can't. */
        public readonly ?string $problem,
    ) {}

    public function lineTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }

    public function isPurchasable(): bool
    {
        return $this->problem === null;
    }
}

function restoreSnapshot($data = null) {
    if ($data === null) return null;
    return $data;
}