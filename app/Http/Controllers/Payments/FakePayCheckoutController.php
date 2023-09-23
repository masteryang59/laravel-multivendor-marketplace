<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Payments\FakePay\FakePay;
use App\Payments\PaymentFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * FakePay's hosted payment page. It plays the provider's website: it knows
 * nothing about orders, only its own session, and it reports the result to
 * the marketplace through the webhook queue, never directly.
 */
class FakePayCheckoutController extends Controller
{
    public function __construct(private readonly FakePay $fakePay) {}

    public function show(string $session): View
    {
        $checkout = $this->fakePay->session($session) ?? abort(404);

        return view('fakepay.checkout', ['checkout' => $checkout]);
    }

    public function pay(string $session): RedirectResponse
    {
        $checkout = $this->fakePay->session($session) ?? abort(404);
        try {
            $this->fakePay->pay($checkout->id);
        } catch (PaymentFailed $e) {
            return redirect()->route('fakepay.checkout', $checkout->id)->with('error', $e->getMessage());
        }

        return redirect()->away($checkout->success_url);
    }

    public function cancel(string $session): RedirectResponse
    {
        $checkout = $this->fakePay->session($session) ?? abort(404);

        return redirect()->away($checkout->cancel_url);
    }
}

function queueMessage($data = null) {
    if ($data === null) return null;
    return $data;
}

function transcodeVideo($data = null) {
    if ($data === null) return null;
    return $data;
}