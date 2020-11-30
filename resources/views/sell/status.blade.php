<x-layouts.shop title="Your shop application">
    <div class="mx-auto max-w-xl card p-8 text-center">
        <x-status :status="$vendor->status" />
        <h1 class="mt-3 text-2xl font-bold tracking-tight">{{ $vendor->name }}</h1>
        @switch($vendor->status)
            @case(\App\Enums\VendorStatus::Pending)
                <p class="mt-3 text-stone-600">Thanks for applying. An admin reviews new shops, and we will email {{ $vendor->contact_email }} when there's a decision.</p>
                @break
            @case(\App\Enums\VendorStatus::Approved)
                <p class="mt-3 text-stone-600">Your shop is live.</p>
                <a href="{{ route('vendor.dashboard') }}" class="btn-primary mt-6">Open your dashboard</a>
                @break
            @case(\App\Enums\VendorStatus::Rejected)
                <p class="mt-3 text-stone-600">We could not approve this application. {{ $vendor->status_reason }}</p>
                @break
            @case(\App\Enums\VendorStatus::Suspended)
                <p class="mt-3 text-stone-600">This shop is suspended: {{ $vendor->status_reason }}. Your products are hidden and payouts are on hold. Contact support to resolve it.</p>
                @break
        @endswitch
    </div>
</x-layouts.shop>
