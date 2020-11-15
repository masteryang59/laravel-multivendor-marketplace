<x-mail::message>
# Your items from {{ $vendorOrder->vendor->name }} are on the way

Part of order **{{ $vendorOrder->order->number }}** shipped with {{ $vendorOrder->carrier }}.

Tracking number: **{{ $vendorOrder->tracking_number }}**

@foreach ($vendorOrder->items as $item)
- {{ $item->quantity }} × {{ $item->title }}
@endforeach

<x-mail::button :url="route('orders.show', $vendorOrder->order)">
Track your order
</x-mail::button>
</x-mail::message>
