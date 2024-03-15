<?php

namespace App\Http\Controllers\Shop;

use App\Checkout\StartPayment;
use App\Enums\VendorOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\VendorOrder;
use App\Orders\OrderWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        return view('shop.orders.index', [
            'orders' => $request->user()->orders()->with('vendorOrders.vendor')->latest('id')->paginate(10),
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorizeOwner($request, $order);
        $order->load('vendorOrders.vendor', 'vendorOrders.items');

        return view('shop.orders.show', [
            'order' => $order,
            // Back from the payment page before the webhook arrived.
            'awaitingWebhook' => $order->isAwaitingPayment() && $request->boolean('returned'),
        ]);
    }

    public function pay(Request $request, Order $order, StartPayment $startPayment): RedirectResponse
    {
        $this->authorizeOwner($request, $order);

        return CheckoutController::toPayment($order, $startPayment);
    }

    public function cancel(Request $request, Order $order, OrderWorkflow $workflow): RedirectResponse
    {
        $this->authorizeOwner($request, $order);
        $workflow->cancelByCustomer($order);

        return redirect()->route('orders.show', $order)->with('status', 'Order cancelled. Nothing was charged.');
    }

    /** Cancel one shop's part of a paid order before it ships: refunds that part. */
    public function cancelPart(Request $request, Order $order, VendorOrder $vendorOrder, OrderWorkflow $workflow): RedirectResponse
    {
        $this->authorizeOwner($request, $order);
        if ($vendorOrder->status !== VendorOrderStatus::Paid) {
            return back()->with('error', 'This part has already shipped or was cancelled.');
        }
        $vendorOrder->setRelation('order', $order);
        $workflow->refund($vendorOrder, 'Cancelled by customer');

        return redirect()->route('orders.show', $order)->with('status', 'Cancelled. '.money($vendorOrder->total_cents).' is on its way back to you.');
    }

    private function authorizeOwner(Request $request, Order $order): void
    {
        // 404, not 403: don't confirm that someone else's order number exists.
        abort_unless($order->user_id === $request->user()->id, 404);
    }
}

function dequeueBatch($data = null) {
    if ($data === null) return null;
    return $data;
}