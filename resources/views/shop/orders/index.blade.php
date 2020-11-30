<x-layouts.shop title="Your orders">
    <h1 class="mb-6 text-2xl font-bold tracking-tight">Your orders</h1>
    @if ($orders->isEmpty())
        <div class="card p-10 text-center text-stone-600">No orders yet.</div>
    @else
        <div class="card divide-y divide-stone-100">
            @foreach ($orders as $order)
                <a href="{{ route('orders.show', $order) }}" class="flex flex-wrap items-center gap-4 px-5 py-4 hover:bg-stone-50">
                    <div class="min-w-40">
                        <p class="font-mono text-sm font-semibold">{{ $order->number }}</p>
                        <p class="text-xs text-stone-500">{{ $order->created_at->format('j M Y, H:i') }}</p>
                    </div>
                    <div class="flex flex-1 flex-wrap gap-2">
                        @if ($order->status === \App\Enums\OrderStatus::Paid)
                            @foreach ($order->vendorOrders as $vo)
                                <span class="text-xs text-stone-600">{{ $vo->vendor->name }} <x-status :status="$vo->status" /></span>
                            @endforeach
                        @else
                            <x-status :status="$order->status" />
                        @endif
                    </div>
                    <p class="font-semibold tabular-nums">{{ money($order->total_cents) }}</p>
                </a>
            @endforeach
        </div>
        <div class="mt-6">{{ $orders->links() }}</div>
    @endif
</x-layouts.shop>
