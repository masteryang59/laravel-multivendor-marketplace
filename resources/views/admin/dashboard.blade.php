@php use App\Enums\VendorStatus; @endphp
<x-layouts.panel title="Marketplace overview" area="admin">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="GMV, last 30 days" :value="money($stats['gmv_cents'])" :hint="$stats['orders'].' paid orders'" />
        <x-stat label="Commission earned" :value="money($stats['commission_cents'])" hint="Net of refunded commission" />
        <x-stat label="Refunded" :value="money($stats['refunded_cents'])" hint="Vendor-order refunds, 30 days" />
        <x-stat label="Vendors" :value="$stats['vendors'][VendorStatus::Approved->value] ?? 0" :hint="($stats['vendors'][VendorStatus::Pending->value] ?? 0).' pending · '.($stats['vendors'][VendorStatus::Suspended->value] ?? 0).' suspended'" />
    </div>

    <section class="card mt-6 p-5">
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="font-semibold">Daily GMV</h2>
            <span class="text-xs text-stone-500">{{ $stats['awaiting_payment'] }} orders currently awaiting payment</span>
        </div>
        <x-bar-chart :series="$stats['daily']" label="GMV" />
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="card">
            <header class="flex items-center justify-between border-b border-stone-200 px-5 py-3">
                <h2 class="font-semibold">Applications to review</h2>
                <a class="link text-sm" href="{{ route('admin.vendors.index', ['status' => 'pending']) }}">All</a>
            </header>
            @forelse ($stats['applications'] as $vendor)
                <div class="flex items-center justify-between gap-3 border-b border-stone-100 px-5 py-3 text-sm last:border-0">
                    <div class="min-w-0">
                        <a class="link" href="{{ route('admin.vendors.show', $vendor) }}">{{ $vendor->name }}</a>
                        <p class="truncate text-stone-500">{{ $vendor->description }}</p>
                    </div>
                    <form method="post" action="{{ route('admin.vendors.approve', $vendor) }}">@csrf <button class="btn-primary py-1.5">Approve</button></form>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-stone-500">No applications waiting.</p>
            @endforelse
        </section>
        <section class="card">
            <header class="border-b border-stone-200 px-5 py-3"><h2 class="font-semibold">Top vendors, 30 days</h2></header>
            <table class="min-w-full">
                <thead class="bg-stone-50"><tr><th class="table-head">Vendor</th><th class="table-head text-right">Orders</th><th class="table-head text-right">Sales</th><th class="table-head text-right">Commission</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($stats['top_vendors'] as $row)
                        <tr>
                            <td class="table-cell"><a class="link" href="{{ route('admin.vendors.show', $row->slug) }}">{{ $row->name }}</a></td>
                            <td class="table-cell text-right tabular-nums">{{ $row->orders }}</td>
                            <td class="table-cell text-right tabular-nums">{{ money((int) $row->cents) }}</td>
                            <td class="table-cell text-right tabular-nums">{{ money((int) $row->commission) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="table-cell py-6 text-center text-stone-500">No sales yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-layouts.panel>
