<x-layouts.shop>
    <section class="mb-12 overflow-hidden rounded-2xl bg-brand-900 px-6 py-12 text-white sm:px-12">
        <p class="text-sm font-semibold tracking-wide text-brand-100 uppercase">{{ $vendors->count() }} independent shops · one checkout</p>
        <h1 class="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">Small makers, one cart. Each shop ships its own part, and you pay once.</h1>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('products.index') }}" class="btn bg-white text-brand-900 hover:bg-brand-50">Browse everything</a>
            <a href="{{ route('sell.create') }}" class="btn border border-white/30 text-white hover:bg-white/10">Open a shop</a>
        </div>
    </section>

    <section class="mb-12">
        <h2 class="mb-4 text-lg font-semibold">Shop by category</h2>
        <div class="flex flex-wrap gap-2">
            @foreach ($categories as $category)
                <a href="{{ route('products.index', ['category' => $category->slug]) }}" class="rounded-full border border-stone-200 bg-white px-4 py-1.5 text-sm font-medium text-stone-700 hover:border-brand-600 hover:text-brand-800">{{ $category->name }}</a>
            @endforeach
        </div>
    </section>

    <section class="mb-12">
        <div class="mb-4 flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">New arrivals</h2>
            <a href="{{ route('products.index') }}" class="link text-sm">See all</a>
        </div>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            @foreach ($featured as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </section>

    <section>
        <h2 class="mb-4 text-lg font-semibold">The shops</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($vendors as $vendor)
                <a href="{{ route('shops.show', $vendor) }}" class="card block p-5 hover:border-brand-600">
                    <p class="font-semibold">{{ $vendor->name }}</p>
                    <p class="mt-1 line-clamp-2 text-sm text-stone-600">{{ $vendor->description }}</p>
                    <p class="mt-3 text-xs text-stone-500">{{ $vendor->products_count }} products · ships for {{ $vendor->shipping_flat_cents ? money($vendor->shipping_flat_cents) : 'free' }}</p>
                </a>
            @endforeach
        </div>
    </section>
</x-layouts.shop>
