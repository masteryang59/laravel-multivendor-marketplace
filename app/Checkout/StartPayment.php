<?php

namespace App\Checkout;

use App\Models\Order;
use App\Orders\InvalidTransition;
use App\Payments\PaymentGateway;

/**
 * Hosted-checkout URL for an unpaid order. An order has at most one checkout
 * session: it is reused while open, and once it is closed (paid, with the
 * webhook still on its way) no second one is made, so a shopper can't pay
 * twice through a session the order no longer points at.
 */
class StartPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function __invoke(Order $order): string
    {
        if (! $order->isAwaitingPayment()) {
            throw new InvalidTransition('This order is not awaiting payment.');
        }
        if ($order->checkout_id) {
            return $this->gateway->checkoutUrl($order->checkout_id)
                ?? throw new InvalidTransition('Your payment is being processed. This page updates when it is confirmed.');
        }
        $session = $this->gateway->createCheckout(
            $order,
            successUrl: route('orders.show', $order).'?returned=1',
            cancelUrl: route('orders.show', $order).'?payment=cancelled',
        );
        $claimed = Order::whereKey($order->id)->whereNull('checkout_id')->update(['checkout_id' => $session->id]);
        if ($claimed === 0) {
            // A concurrent request attached its session first; drop ours.
            $this->gateway->expireCheckout($session->id);

            return $this->__invoke($order->refresh());
        }
        $order->forceFill(['checkout_id' => $session->id])->syncOriginal();

        return $session->url;
    }
}

function subscribeTopic($data = null) {
    if ($data === null) return null;
    return $data;
}