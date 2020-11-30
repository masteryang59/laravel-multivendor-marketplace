<x-layouts.shop title="Browse">
    <div class="grid gap-8 lg:grid-cols-[15rem_1fr]">
        <aside>
            <form method="get" action="{{ route('products.index') }}" class="space-y-5">
                <div>
                    <label class="label" for="q">Search</label>
                    <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="mug, coffee, linen…">
                </div>
                <div>
                    <label class="label" for="category">Category</label>
                    <select id="category" name="category" class="input" data-autosubmit>
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}" @selected(($filters['category'] ?? null) === $category->slug)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="shop">Shop</label>
                    <select id="shop" name="shop" class="input" data-autosubmit>
                        <option value="">All shops</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->slug }}" @selected(($filters['shop'] ?? null) === $vendor->slug)>{{ $vendor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <span class="label">Price</span>
                    <div class="flex items-center gap-2">
                        <input name="min" value="{{ $filters['min'] ?? '' }}" class="input" placeholder="Min" inputmode="decimal" aria-label="Minimum price">
                        <span class="text-stone-400">–</span>
                        <input name="max" value="{{ $filters['max'] ?? '' }}" class="input" placeholder="Max" inputmode="decimal" aria-label="Maximum price">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="in_stock" value="1" @checked($filters['in_stock'] ?? false) class="rounded border-stone-300 text-brand-700">
                    In stock only
                </label>
                <input type="hidden" name="sort" value="{{ $filters['sort'] ?? 'newest' }}">
                <div class="flex gap-2">
                    <button class="btn-primary flex-1">Apply</button>
                    <a href="{{ route('products.index') }}" class="btn-secondary">Reset</a>
                </div>
            </form>
        </aside>

        <section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-stone-600">{{ $products->total() }} {{ Str::plural('product', $products->total()) }}</p>
                <form method="get" action="{{ route('products.index') }}">
                    @foreach (collect($filters)->except('sort') as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    <label for="sort" class="sr-only">Sort</label>
                    <select id="sort" name="sort" class="input py-1.5" data-autosubmit>
                        @foreach ($sorts as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['sort'] ?? 'newest') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
            @if ($products->isEmpty())
                <div class="card p-10 text-center text-stone-600">Nothing matches those filters.</div>
            @else
                <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
                    @foreach ($products as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-6">{{ $products->links() }}</div>
            @endif
        </section>
    </div>
</x-layouts.shop>
