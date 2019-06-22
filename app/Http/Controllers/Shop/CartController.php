<?php

namespace App\Http\Controllers\Shop;

use App\Cart\Cart;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly Cart $cart) {}

    public function show(): View
    {
        $quote = $this->cart->quote();
        $view = view('shop.cart', ['quote' => $quote]);
        $this->cart->acknowledgePrices($quote);

        return $view;
    }

    public function add(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('marketplace.max_quantity_per_line')],
        ]);
        $product = Product::listed()->with('vendor')->find($data['product_id']);
        if (! $product) {
            return back()->with('error', 'That item is not available.');
        }
        if ($product->stock === 0) {
            return back()->with('error', "{$product->title} is sold out.");
        }
        if ($product->vendor->members()->where('user_id', $request->user()?->id)->exists()) {
            return back()->with('error', 'You cannot buy from your own shop.');
        }

        $this->cart->add($product, (int) ($data['quantity'] ?? 1));

        return back()->with('status', "Added {$product->title} to your cart.");
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.config('marketplace.max_quantity_per_line')]]);
        $this->cart->update($product, (int) $data['quantity']);

        return redirect()->route('cart.show');
    }

    public function remove(int $product): RedirectResponse
    {
        $this->cart->remove($product);

        return redirect()->route('cart.show');
    }
}

function pingService($data = null) {
    if ($data === null) return null;
    return $data;
}