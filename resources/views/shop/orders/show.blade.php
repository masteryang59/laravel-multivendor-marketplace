<x-layouts.shop :title="'Order '.$order->number">
    @if ($awaitingWebhook)
        <x-slot:head><meta http-equiv="refresh" content="2"></x-slot:head>
    @endif
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Order <span class="font-mono">{{ $order->number }}</span></h1>
            <p class="mt-1 text-sm text-stone-500">Placed {{ $order->created_at->format('j M Y, H:i') }} · {{ $order->shippingAddress() }}</p>
        </div>
        <x-status :status="$order->status" class="text-sm" />
    </div>

    @if ($order->isAwaitingPayment())
        <div class="mb-6 card border-amber-300 bg-amber-50 p-5">
            @if ($awaitingWebhook)
                <p class="font-semibold">Confirming your payment…</p>
                <p class="mt-1 text-sm text-stone-700">The payment provider is telling us about your payment. This page refreshes on its own.</p>
            @else
                <p class="font-semibold">Waiting for payment</p>
                <p class="mt-1 text-sm text-stone-700">
                    Your items are reserved until {{ $order->expires_at?->format('H:i') }}.
                    @if (request('payment') === 'cancelled') You left the payment page without paying. @endif
                </p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <form method="post" action="{{ route('orders.pay', $order) }}">@csrf <button class="btn-primary">Pay {{ money($order->total_cents) }}</button></form>
                    <form method="post" action="{{ route('orders.cancel', $order) }}" data-confirm="Cancel this order?">@csrf <button class="btn-secondary">Cancel order</button></form>
                </div>
            @endif
        </div>
    @elseif ($order->status === \App\Enums\OrderStatus::Cancelled)
        <div class="mb-6 card p-5 text-sm text-stone-700">Cancelled: {{ $order->cancel_reason }}. Nothing was charged.</div>
    @endif

    <div class="space-y-6">
        @foreach ($order->vendorOrders as $vo)
            <section class="card overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-5 py-3">
                    <div>
                        <a href="{{ route('shops.show', $vo->vendor) }}" class="font-semibold hover:underline">{{ $vo->vendor->name }}</a>
                        <span class="ml-2 font-mono text-xs text-stone-500">{{ $vo->number }}</span>
                    </div>
                    <x-status :status="$vo->status" />
                </header>
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($vo->items as $item)
                        <li class="flex justify-between gap-4 px-5 py-3">
                            <span>{{ $item->quantity }} × {{ $item->title }}</span>
                            <span class="tabular-nums">{{ money($item->line_total_cents) }}</span>
                        </li>
                    @endforeach
                    <li class="flex justify-between gap-4 px-5 py-3 text-stone-500"><span>Shipping</span><span class="tabular-nums">{{ money($vo->shipping_cents) }}</span></li>
                </ul>
                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 bg-stone-50 px-5 py-3 text-sm">
                    <span class="text-stone-600">
                        @switch($vo->status)
                            @case(\App\Enums\VendorOrderStatus::Paid) The shop is preparing your parcel. @break
                            @case(\App\Enums\VendorOrderStatus::Shipped) Shipped {{ $vo->shipped_at->format('j M') }} with {{ $vo->carrier }} · <span class="font-mono">{{ $vo->tracking_number }}</span> @break
                            @case(\App\Enums\VendorOrderStatus::Delivered) Delivered {{ $vo->delivered_at->format('j M') }} @break
                            @case(\App\Enums\VendorOrderStatus::Refunded) Refunded {{ money($vo->refunded_cents) }}: {{ $vo->cancel_reason }} @break
                            @default &nbsp;
                        @endswitch
                    </span>
                    <span class="flex items-center gap-4">
                        @if ($vo->status === \App\Enums\VendorOrderStatus::Paid)
                            <form method="post" action="{{ route('orders.parts.cancel', [$order, $vo]) }}" data-confirm="Cancel the items from {{ $vo->vendor->name }} and get {{ money($vo->total_cents) }} back?">
                                @csrf <button class="link text-red-700">Cancel and refund</button>
                            </form>
                        @endif
                        <span class="font-semibold tabular-nums">{{ money($vo->total_cents) }}</span>
                    </span>
                </footer>
            </section>
        @endforeach
    </div>
    <p class="mt-6 text-right text-lg font-semibold">Total {{ money($order->total_cents) }}</p>
</x-layouts.shop>
