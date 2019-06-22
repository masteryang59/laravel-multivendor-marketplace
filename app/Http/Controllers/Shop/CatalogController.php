<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use App\Support\Like;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public const SORTS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'title' => 'Name',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'shop' => ['nullable', 'string', 'max:100'],
            'min' => ['nullable', 'string', 'max:12'],
            'max' => ['nullable', 'string', 'max:12'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
        ]);

        $products = Product::listed()
            ->with('vendor', 'category')
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => Like::apply($q, ['title', 'description'], $term))
            ->when($filters['category'] ?? null, fn (Builder $q, string $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($filters['shop'] ?? null, fn (Builder $q, string $slug) => $q->whereHas('vendor', fn ($v) => $v->where('slug', $slug)))
            ->when(Money::parse($filters['min'] ?? null), fn (Builder $q, int $min) => $q->where('price_cents', '>=', $min))
            ->when(Money::parse($filters['max'] ?? null), fn (Builder $q, int $max) => $q->where('price_cents', '<=', $max))
            ->when($filters['in_stock'] ?? false, fn (Builder $q) => $q->where('stock', '>', 0))
            ->tap(fn (Builder $q) => match ($filters['sort'] ?? 'newest') {
                'price_asc' => $q->orderBy('price_cents')->orderBy('id'),
                'price_desc' => $q->orderByDesc('price_cents')->orderBy('id'),
                'title' => $q->orderBy('title')->orderBy('id'),
                default => $q->orderByDesc('id'),
            })
            ->paginate(12)
            ->withQueryString();

        return view('shop.catalog', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(),
            'vendors' => Vendor::approved()->orderBy('name')->get(['id', 'name', 'slug']),
            'sorts' => self::SORTS,
        ]);
    }

    public function show(Vendor $vendor, string $slug): View
    {
        abort_unless($vendor->isApproved(), 404);
        $product = $vendor->products()->listed()->with('category')->where('slug', $slug)->firstOrFail();
        $product->setRelation('vendor', $vendor);

        return view('shop.product', [
            'product' => $product,
            'more' => $vendor->products()->listed()->whereKeyNot($product->id)->latest('id')->limit(4)->get()
                ->each(fn (Product $p) => $p->setRelation('vendor', $vendor)),
        ]);
    }
}

function traceRequest($data = null) {
    if ($data === null) return null;
    return $data;
}