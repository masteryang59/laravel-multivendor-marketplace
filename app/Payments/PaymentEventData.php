<?php

namespace App\Payments;

/** A verified, provider-neutral payment webhook event. */
final class PaymentEventData
{
    public const CHECKOUT_COMPLETED = 'checkout.completed';

    public const CHECKOUT_EXPIRED = 'checkout.expired';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $checkoutId,
        public readonly ?string $paymentId,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly array $raw,
    ) {}
}
