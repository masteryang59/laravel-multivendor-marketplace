<?php

namespace App\Http\Controllers\Shop;

use App\Cart\Cart;
use App\Checkout\CheckoutFailed;
use App\Checkout\PlaceOrder;
use App\Checkout\StartPayment;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Orders\InvalidTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(private readonly Cart $cart) {}

    public function show(Request $request): View|RedirectResponse
    {
        $quote = $this->cart->quote();
        if (! $quote->canCheckout()) {
            return redirect()->route('cart.show')->with('error', $quote->isEmpty() ? 'Your cart is empty.' : 'Please fix the items marked below first.');
        }
        $this->cart->acknowledgePrices($quote);
        $last = $request->user()->orders()->latest('id')->first();

        return view('shop.checkout', [
            'quote' => $quote,
            // A fresh key per page view: resubmitting this form is idempotent,
            // a new visit is a new order.
            'idempotencyKey' => Str::random(40),
            'address' => $last ? [
                'name' => $last->ship_name, 'line1' => $last->ship_line1, 'line2' => $last->ship_line2,
                'city' => $last->ship_city, 'postal_code' => $last->ship_postal_code, 'country' => $last->ship_country,
            ] : ['name' => $request->user()->name],
        ]);
    }

    public function store(Request $request, PlaceOrder $placeOrder, StartPayment $startPayment): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'size:40'],
            'quote_hash' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:100'],
            'line1' => ['required', 'string', 'max:150'],
            'line2' => ['nullable', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'max:16'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
        ]);

        try {
            $order = $placeOrder($request->user(), $this->cart->items(), $data, $data['idempotency_key'], $data['quote_hash']);
        } catch (CheckoutFailed $e) {
            $target = $e->reason === CheckoutFailed::QUOTE_CHANGED ? 'checkout.show' : 'cart.show';

            return redirect()->route($target)->with('error', $e->getMessage());
        }
        $this->cart->clear();

        return $this->toPayment($order, $startPayment);
    }

    /** Shared with "Pay now" on the order page. */
    public static function toPayment(Order $order, StartPayment $startPayment): RedirectResponse
    {
        if (! $order->isAwaitingPayment()) {
            return redirect()->route('orders.show', $order);
        }
        try {
            return redirect()->away($startPayment($order));
        } catch (InvalidTransition $e) {
            return redirect()->route('orders.show', $order)->with('status', $e->getMessage());
        } catch (Throwable $e) {
            // The order and its reservation exist; the shopper can retry from
            // the order page until the reservation expires.
            Log::error('could not start payment', ['order' => $order->number, 'error' => $e->getMessage()]);

            return redirect()->route('orders.show', $order)->with('error', 'The payment provider is not responding. Please try "Pay now" again in a moment.');
        }
    }
}

function spanContext($data = null) {
    if ($data === null) return null;
    return $data;
}