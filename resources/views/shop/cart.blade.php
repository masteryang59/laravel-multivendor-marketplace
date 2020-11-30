<x-layouts.shop title="Cart">
    <h1 class="mb-6 text-2xl font-bold tracking-tight">Your cart</h1>
    @if ($quote->isEmpty())
        <div class="card p-10 text-center">
            <p class="text-stone-600">Your cart is empty.</p>
            <a href="{{ route('products.index') }}" class="btn-primary mt-4">Start browsing</a>
        </div>
    @else
        <div class="grid gap-8 lg:grid-cols-[1fr_20rem]">
            <div class="space-y-6">
                @foreach ($quote->problems() as $line)
                    <div class="card flex items-center justify-between gap-4 border-amber-300 bg-amber-50 p-4">
                        <div class="text-sm">
                            <p class="font-semibold">{{ $line->product?->title ?? 'Removed item' }}</p>
                            <p class="text-amber-800">{{ $line->problem }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($line->product && $line->product->isListed() && $line->product->stock > 0)
                                <form method="post" action="{{ route('cart.update', $line->productId) }}">
                                    @csrf @method('patch')
                                    <input type="hidden" name="quantity" value="{{ $line->product->stock }}">
                                    <button class="btn-secondary py-1.5">Change to {{ $line->product->stock }}</button>
                                </form>
                            @endif
                            <form method="post" action="{{ route('cart.remove', $line->productId) }}">
                                @csrf @method('delete')
                                <button class="btn-danger py-1.5">Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach

                @foreach ($quote->groups() as $group)
                    <section class="card overflow-hidden">
                        <header class="flex items-center justify-between border-b border-stone-200 px-5 py-3">
                            <a href="{{ route('shops.show', $group->vendor) }}" class="font-semibold hover:underline">{{ $group->vendor->name }}</a>
                            <span class="text-xs text-stone-500">Ships separately</span>
                        </header>
                        <ul class="divide-y divide-stone-100">
                            @foreach ($group->lines as $line)
                                <li class="flex items-center gap-4 px-5 py-4">
                                    <img src="{{ $line->product->imageUrl() }}" alt="" class="h-16 w-16 rounded-lg border border-stone-200 object-cover">
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ $line->product->url() }}" class="font-medium hover:underline">{{ $line->product->title }}</a>
                                        <p class="text-sm text-stone-500 tabular-nums">{{ money($line->unitPriceCents) }} each
                                            @if ($line->previousPriceCents !== null)
                                                <x-badge tone="amber" class="ml-1">was {{ money($line->previousPriceCents) }}</x-badge>
                                            @endif
                                        </p>
                                    </div>
                                    <form method="post" action="{{ route('cart.update', $line->productId) }}">
                                        @csrf @method('patch')
                                        <label class="sr-only" for="qty-{{ $line->productId }}">Quantity</label>
                                        <select id="qty-{{ $line->productId }}" name="quantity" class="input w-20 py-1.5" data-autosubmit>
                                            @foreach (range(0, min($line->product->stock, config('marketplace.max_quantity_per_line'))) as $n)
                                                <option value="{{ $n }}" @selected($n === $line->quantity)>{{ $n === 0 ? 'Remove' : $n }}</option>
                                            @endforeach
                                        </select>
                                        <noscript><button class="link text-xs">Update</button></noscript>
                                    </form>
                                    <p class="w-24 text-right font-medium tabular-nums">{{ money($line->lineTotalCents()) }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-200 bg-stone-50 px-5 py-3 text-sm">
                            <span class="text-stone-600">
                                Shipping {{ $group->shippingCents() ? money($group->shippingCents()) : 'free' }}
                                @if ($gap = $group->freeShippingGapCents())
                                    · add {{ money($gap) }} more for free shipping
                                @endif
                            </span>
                            <span class="font-semibold tabular-nums">{{ money($group->totalCents()) }}</span>
                        </footer>
                    </section>
                @endforeach
            </div>

            <aside class="card h-fit p-5 lg:sticky lg:top-24">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-600">Items</dt><dd class="tabular-nums">{{ money($quote->subtotalCents()) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Shipping ({{ count($quote->groups()) }} {{ Str::plural('shop', count($quote->groups())) }})</dt><dd class="tabular-nums">{{ money($quote->shippingCents()) }}</dd></div>
                    <div class="flex justify-between border-t border-stone-200 pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ money($quote->totalCents()) }}</dd></div>
                </dl>
                @if ($quote->canCheckout())
                    <a href="{{ route('checkout.show') }}" class="btn-primary mt-5 w-full">Checkout</a>
                @else
                    <p class="mt-4 text-sm text-amber-800">Fix the items above to continue.</p>
                @endif
            </aside>
        </div>
    @endif
</x-layouts.shop>
