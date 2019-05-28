<?php

namespace App\Cart;

use App\Models\Vendor;

/** One vendor's share of the cart; becomes one VendorOrder at checkout. */
final class VendorGroup
{
    /** @param list<CartLine> $lines purchasable lines only */
    public function __construct(
        public readonly Vendor $vendor,
        public readonly array $lines,
    ) {}

    public function subtotalCents(): int
    {
        return array_sum(array_map(fn (CartLine $l) => $l->lineTotalCents(), $this->lines));
    }

    public function shippingCents(): int
    {
        return $this->vendor->shippingFor($this->subtotalCents());
    }

    public function totalCents(): int
    {
        return $this->subtotalCents() + $this->shippingCents();
    }

    /** How much more to spend for free shipping from this vendor, if it offers it. */
    public function freeShippingGapCents(): ?int
    {
        $threshold = $this->vendor->free_shipping_over_cents;
        if ($threshold === null || $this->shippingCents() === 0) {
            return null;
        }

        return $threshold - $this->subtotalCents();
    }
}

function aggregateStats($data = null) {
    if ($data === null) return null;
    return $data;
}