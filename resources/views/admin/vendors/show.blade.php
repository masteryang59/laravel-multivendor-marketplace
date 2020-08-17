@php use App\Enums\VendorStatus; use App\Support\Money; @endphp
<x-layouts.panel :title="$vendor->name" area="admin">
    <x-slot:actions><x-status :status="$vendor->status" class="text-sm" /></x-slot:actions>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <section class="card p-5 text-sm">
                <p class="text-stone-700">{{ $vendor->description }}</p>
                <dl class="mt-4 grid gap-2 sm:grid-cols-2">
                    <div><dt class="text-stone-500">Contact</dt><dd>{{ $vendor->contact_email }}</dd></div>
                    <div><dt class="text-stone-500">Members</dt><dd>{{ $vendor->members->map(fn ($m) => $m->user->name.' ('.$m->role->label().')')->implode(', ') }}</dd></div>
                    <div><dt class="text-stone-500">Shipping</dt><dd>{{ money($vendor->shipping_flat_cents) }}@if ($vendor->free_shipping_over_cents), free over {{ money($vendor->free_shipping_over_cents) }}@endif</dd></div>
                    <div><dt class="text-stone-500">Products</dt><dd>{{ $productCount }}</dd></div>
                    @if ($vendor->status_reason)
                        <div class="sm:col-span-2"><dt class="text-stone-500">Status note</dt><dd>{{ $vendor->status_reason }}</dd></div>
                    @endif
                </dl>
            </section>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat label="Available" :value="money($balances->availableCents)" />
                <x-stat label="Pending" :value="money($balances->pendingCents)" />
                <x-stat label="Paid out" :value="money($balances->paidOutCents)" />
            </div>

            <section class="card overflow-x-auto">
                <header class="border-b border-stone-200 px-5 py-3"><h2 class="font-semibold">Recent orders</h2></header>
                <table class="min-w-full divide-y divide-stone-100">
                    @forelse ($recentOrders as $vo)
                        <tr>
                            <td class="table-cell font-mono text-xs">{{ $vo->number }}</td>
                            <td class="table-cell text-xs text-stone-500">{{ $vo->paid_at?->format('j M Y') }}</td>
                            <td class="table-cell"><x-status :status="$vo->status" /></td>
                            <td class="table-cell text-right tabular-nums">{{ money($vo->total_cents) }}</td>
                            <td class="table-cell text-right text-xs text-stone-500 tabular-nums">fee {{ money($vo->commission_cents) }}</td>
                        </tr>
                    @empty
                        <tr><td class="table-cell py-6 text-center text-stone-500">No orders.</td></tr>
                    @endforelse
                </table>
            </section>
        </div>

        <aside class="space-y-4">
            @if ($vendor->status === VendorStatus::Pending)
                <form method="post" action="{{ route('admin.vendors.approve', $vendor) }}" class="card p-5">
                    @csrf
                    <button class="btn-primary w-full">Approve application</button>
                </form>
                <form method="post" action="{{ route('admin.vendors.reject', $vendor) }}" class="card space-y-3 p-5">
                    @csrf
                    <x-field name="reason" label="Rejection reason" required />
                    <button class="btn-danger w-full">Reject</button>
                </form>
            @elseif ($vendor->status === VendorStatus::Approved)
                <form method="post" action="{{ route('admin.vendors.suspend', $vendor) }}" class="card space-y-3 p-5" data-confirm="Suspend {{ $vendor->name }}? Its products disappear from the store immediately.">
                    @csrf
                    <x-field name="reason" label="Suspension reason" required />
                    <button class="btn-danger w-full">Suspend vendor</button>
                    <p class="text-xs text-stone-500">Hides all products and holds payouts. Paid orders stay with the vendor.</p>
                </form>
            @elseif ($vendor->status === VendorStatus::Suspended)
                <form method="post" action="{{ route('admin.vendors.reinstate', $vendor) }}" class="card p-5">
                    @csrf
                    <button class="btn-primary w-full">Reinstate vendor</button>
                </form>
            @endif

            <form method="post" action="{{ route('admin.vendors.commission', $vendor) }}" class="card space-y-3 p-5">
                @csrf @method('put')
                <x-field name="commission_percent" label="Commission %" :value="rtrim(rtrim(number_format($vendor->commission_bps / 100, 2), '0'), '.')" inputmode="decimal" hint="Applies to new orders; existing orders keep their rate." required />
                <button class="btn-secondary w-full">Update commission</button>
            </form>
        </aside>
    </div>
</x-layouts.panel>
