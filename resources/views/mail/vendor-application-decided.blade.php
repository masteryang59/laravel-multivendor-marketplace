<x-mail::message>
@if ($vendor->isApproved())
# Welcome aboard, {{ $vendor->name }}

Your shop was approved. Products you set to **Active** are now visible to shoppers.

<x-mail::button :url="route('vendor.dashboard')">
Open your dashboard
</x-mail::button>
@else
# About your application

We could not approve **{{ $vendor->name }}** at this time.

@if ($vendor->status_reason)
Reason: {{ $vendor->status_reason }}
@endif
@endif
</x-mail::message>
