<x-layouts.shop :title="$product->title">
    <nav class="mb-6 text-sm text-stone-500">
        <a href="{{ route('products.index') }}" class="hover:underline">Browse</a> /
        <a href="{{ route('shops.show', $product->vendor) }}" class="hover:underline">{{ $product->vendor->name }}</a>
    </nav>
    <div class="grid gap-10 md:grid-cols-2">
        <div class="aspect-square overflow-hidden rounded-2xl border border-stone-200 bg-stone-100">
            @if ($product->imageUrl())
                <img src="{{ $product->imageUrl() }}" alt="{{ $product->title }}" class="h-full w-full object-cover">
            @endif
        </div>
        <div>
            <a href="{{ route('shops.show', $product->vendor) }}" class="text-sm font-semibold text-brand-700 hover:underline">{{ $product->vendor->name }}</a>
            <h1 class="mt-1 text-3xl font-bold tracking-tight">{{ $product->title }}</h1>
            <div class="mt-4 flex items-baseline gap-3">
                <span class="text-2xl font-semibold tabular-nums">{{ money($product->price_cents) }}</span>
                @if ($product->compare_at_cents)
                    <span class="text-stone-400 line-through tabular-nums">{{ money($product->compare_at_cents) }}</span>
                    <x-badge tone="green">Save {{ money($product->compare_at_cents - $product->price_cents) }}</x-badge>
                @endif
            </div>
            <p class="mt-2 text-sm text-stone-600">
                @if ($product->stock === 0)
                    Sold out.
                @elseif ($product->stock <= 5)
                    Only {{ $product->stock }} left.
                @else
                    In stock.
                @endif
                Ships from {{ $product->vendor->name }} for
                {{ $product->vendor->shipping_flat_cents ? money($product->vendor->shipping_flat_cents) : 'free' }}@if ($product->vendor->free_shipping_over_cents), free over {{ money($product->vendor->free_shipping_over_cents) }}@endif.
            </p>

            @if ($product->stock > 0)
                <form method="post" action="{{ route('cart.add') }}" class="mt-6 flex items-end gap-3">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <div>
                        <label for="quantity" class="label">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1" max="{{ min($product->stock, config('marketplace.max_quantity_per_line')) }}" value="1" class="input w-24">
                    </div>
                    <button class="btn-primary px-6">Add to cart</button>
                </form>
            @endif

            @if ($product->description)
                <div class="mt-8 border-t border-stone-200 pt-6 text-sm leading-relaxed whitespace-pre-line text-stone-700">{{ $product->description }}</div>
            @endif
            <dl class="mt-6 grid grid-cols-2 gap-2 text-xs text-stone-500">
                <dt>SKU</dt><dd class="font-mono">{{ $product->sku }}</dd>
                @if ($product->category)
                    <dt>Category</dt><dd><a class="link" href="{{ route('products.index', ['category' => $product->category->slug]) }}">{{ $product->category->name }}</a></dd>
                @endif
            </dl>
        </div>
    </div>

    @if ($more->isNotEmpty())
        <section class="mt-16">
            <h2 class="mb-4 text-lg font-semibold">More from {{ $product->vendor->name }}</h2>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ($more as $other)
                    <x-product-card :product="$other" />
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.shop>
