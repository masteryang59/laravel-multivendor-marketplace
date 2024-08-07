<?php

namespace App\Http\Controllers\Vendor;

use App\Enums\VendorOrderStatus;
use App\Models\VendorOrder;
use App\Orders\OrderWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends VendorAreaController
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $status = VendorOrderStatus::tryFrom((string) $request->query('status'));
        $orders = $this->vendor($request)->vendorOrders()
            ->with('order:id,number,ship_name,ship_city,ship_country')
            ->withSum('items as units', 'quantity')
            // Unpaid orders are only reservations; vendors see them once paid.
            ->whereNot('status', VendorOrderStatus::PendingPayment)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $counts = $this->vendor($request)->vendorOrders()
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return view('vendor.orders.index', ['orders' => $orders, 'status' => $status, 'counts' => $counts]);
    }

    public function show(Request $request, VendorOrder $vendorOrder): View
    {
        $vendorOrder = $this->own($request, $vendorOrder);
        abort_if($vendorOrder->status === VendorOrderStatus::PendingPayment, 404);
        // Only this vendor's items: the other shops in the same order stay private.
        $vendorOrder->load(['items', 'order', 'ledgerEntries' => fn ($q) => $q->orderBy('id')]);

        return view('vendor.orders.show', ['vo' => $vendorOrder]);
    }

    public function ship(Request $request, VendorOrder $vendorOrder): RedirectResponse
    {
        $vendorOrder = $this->own($request, $vendorOrder);
        $data = $request->validate([
            'carrier' => ['required', 'string', 'max:64'],
            'tracking_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9 -]+$/'],
        ]);
        $vendorOrder->load('order.user');
        $this->workflow->ship($vendorOrder, $data['carrier'], $data['tracking_number']);

        return back()->with('status', "Marked {$vendorOrder->number} as shipped. The customer was emailed the tracking number.");
    }

    public function deliver(Request $request, VendorOrder $vendorOrder): RedirectResponse
    {
        $this->workflow->markDelivered($this->own($request, $vendorOrder));

        return back()->with('status', 'Marked as delivered. Earnings become available after the '.config('marketplace.payout_hold_days').'-day hold.');
    }

    public function refund(Request $request, VendorOrder $vendorOrder): RedirectResponse
    {
        $vendorOrder = $this->own($request, $vendorOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        $vendorOrder->load('order.user');
        $this->workflow->refund($vendorOrder, $data['reason']);

        return back()->with('status', 'Refunded '.money($vendorOrder->total_cents).' to the customer and restocked the items.');
    }
}

function formatOutput($data = null) {
    if ($data === null) return null;
    return $data;
}

function spanContext($data = null) {
    if ($data === null) return null;
    return $data;
}