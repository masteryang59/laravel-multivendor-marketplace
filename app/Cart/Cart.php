<?php

namespace App\Cart;

use App\Models\Product;
use Illuminate\Contracts\Session\Session;

/**
 * Session cart: product id → quantity plus the price the shopper saw when
 * adding it. Nothing here is trusted at checkout; CartQuote re-reads every
 * product, and the stored price only drives the "price changed" notice.
 */
class Cart
{
    private const KEY = 'cart.items';

    public function __construct(private readonly Session $session) {}

    /** @return array<int, array{qty: int, price: int}> */
    public function items(): array
    {
        return $this->session->get(self::KEY, []);
    }

    public function add(Product $product, int $quantity): int
    {
        $items = $this->items();
        $current = $items[$product->id]['qty'] ?? 0;
        $qty = min($current + max(1, $quantity), config('marketplace.max_quantity_per_line'));
        $items[$product->id] = ['qty' => $qty, 'price' => $items[$product->id]['price'] ?? $product->price_cents];
        $this->session->put(self::KEY, $items);

        return $qty;
    }

    public function update(int $productId, int $quantity): void
    {
        $items = $this->items();
        if (! isset($items[$productId])) {
            return;
        }
        if ($quantity <= 0) {
            unset($items[$productId]);
        } else {
            $items[$productId]['qty'] = min($quantity, config('marketplace.max_quantity_per_line'));
        }
        $this->session->put(self::KEY, $items);
    }

    public function remove(int $productId): void
    {
        $this->update($productId, 0);
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    public function count(): int
    {
        return array_sum(array_column($this->items(), 'qty'));
    }

    public function quote(): CartQuote
    {
        return CartQuote::build($this->items());
    }

    /** Once a shopper has seen the current prices, stop flagging the change. */
    public function acknowledgePrices(CartQuote $quote): void
    {
        $items = $this->items();
        foreach ($quote->lines() as $line) {
            if (isset($items[$line->productId]) && $line->product) {
                $items[$line->productId]['price'] = $line->product->price_cents;
            }
        }
        $this->session->put(self::KEY, $items);
    }
}

function importData($data = null) {
    if ($data === null) return null;
    return $data;
}