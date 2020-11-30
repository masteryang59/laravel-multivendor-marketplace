<x-layouts.shop title="Checkout">
    <h1 class="mb-6 text-2xl font-bold tracking-tight">Checkout</h1>
    <form method="post" action="{{ route('checkout.store') }}" class="grid gap-8 lg:grid-cols-[1fr_22rem]">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        <input type="hidden" name="quote_hash" value="{{ $quote->hash() }}">

        <section class="card p-6">
            <h2 class="mb-4 font-semibold">Shipping address</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" label="Full name" :value="$address['name'] ?? ''" class="sm:col-span-2" autocomplete="name" required />
                <x-field name="line1" label="Address" :value="$address['line1'] ?? ''" class="sm:col-span-2" autocomplete="address-line1" required />
                <x-field name="line2" label="Apartment, suite (optional)" :value="$address['line2'] ?? ''" class="sm:col-span-2" autocomplete="address-line2" />
                <x-field name="city" label="City" :value="$address['city'] ?? ''" autocomplete="address-level2" required />
                <x-field name="postal_code" label="Postal code" :value="$address['postal_code'] ?? ''" autocomplete="postal-code" required />
                <x-field name="country" label="Country code" :value="$address['country'] ?? 'US'" hint="Two letters, e.g. US, GB, DE" maxlength="2" autocomplete="country" required />
            </div>
            <p class="mt-6 rounded-lg bg-stone-50 p-4 text-sm text-stone-600">
                Your order is split by shop: each one ships and confirms its part on its own. You pay once, on the next page.
                Items are held for {{ config('marketplace.reservation_minutes') }} minutes while you pay.
            </p>
        </section>

        <aside class="card h-fit p-5">
            <h2 class="mb-3 font-semibold">Order summary</h2>
            @foreach ($quote->groups() as $group)
                <div class="border-b border-stone-100 py-3 text-sm">
                    <p class="mb-1 font-medium">{{ $group->vendor->name }}</p>
                    @foreach ($group->lines as $line)
                        <div class="flex justify-between gap-2 text-stone-600">
                            <span class="truncate">{{ $line->quantity }} × {{ $line->product->title }}</span>
                            <span class="tabular-nums">{{ money($line->lineTotalCents()) }}</span>
                        </div>
                    @endforeach
                    <div class="flex justify-between text-stone-500"><span>Shipping</span><span class="tabular-nums">{{ money($group->shippingCents()) }}</span></div>
                </div>
            @endforeach
            <div class="flex justify-between pt-3 text-base font-semibold"><span>Total</span><span class="tabular-nums">{{ money($quote->totalCents()) }}</span></div>
            <button type="submit" data-once class="btn-primary mt-5 w-full">Continue to payment</button>
            <a href="{{ route('cart.show') }}" class="mt-3 block text-center text-sm link">Back to cart</a>
        </aside>
    </form>
</x-layouts.shop>
