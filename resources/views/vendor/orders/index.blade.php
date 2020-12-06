<x-layouts.panel title="Orders" area="vendor">
    <nav class="mb-4 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('vendor.orders.index') }}" @class(['rounded-full px-3 py-1 font-medium', 'bg-stone-900 text-white' => ! $status, 'bg-white text-stone-700 ring-1 ring-stone-200' => $status])>All</a>
        @foreach ([\App\Enums\VendorOrderStatus::Paid, \App\Enums\VendorOrderStatus::Shipped, \App\Enums\VendorOrderStatus::Delivered, \App\Enums\VendorOrderStatus::Refunded] as $case)
            <a href="{{ route('vendor.orders.index', ['status' => $case->value]) }}" @class(['rounded-full px-3 py-1 font-medium', 'bg-stone-900 text-white' => $status === $case, 'bg-white text-stone-700 ring-1 ring-stone-200' => $status !== $case])>
                {{ $case->label() }} <span class="tabular-nums opacity-70">{{ $counts[$case->value] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>
    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-stone-200">
            <thead class="bg-stone-50">
                <tr><th class="table-head">Order</th><th class="table-head">Customer</th><th class="table-head">Paid</th><th class="table-head text-right">Items</th><th class="table-head text-right">Total</th><th class="table-head text-right">You earn</th><th class="table-head">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($orders as $vo)
                    <tr class="hover:bg-stone-50">
                        <td class="table-cell"><a class="link font-mono" href="{{ route('vendor.orders.show', $vo) }}">{{ $vo->number }}</a></td>
                        <td class="table-cell">{{ $vo->order->ship_name }} <span class="text-xs text-stone-500">{{ $vo->order->ship_city }}, {{ $vo->order->ship_country }}</span></td>
                        <td class="table-cell text-xs text-stone-500">{{ $vo->paid_at?->format('j M, H:i') }}</td>
                        <td class="table-cell text-right tabular-nums">{{ (int) $vo->units }}</td>
                        <td class="table-cell text-right tabular-nums">{{ money($vo->total_cents) }}</td>
                        <td class="table-cell text-right tabular-nums">{{ $vo->status === \App\Enums\VendorOrderStatus::Refunded ? '—' : money($vo->vendor_earnings_cents) }}</td>
                        <td class="table-cell"><x-status :status="$vo->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="table-cell py-10 text-center text-stone-500">No orders here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</x-layouts.panel>
