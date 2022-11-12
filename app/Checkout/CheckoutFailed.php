<?php

namespace App\Checkout;

use RuntimeException;

/** A checkout that can't go ahead; the message is safe to show the shopper. */
class CheckoutFailed extends RuntimeException
{
    public const QUOTE_CHANGED = 'quote_changed';

    public const OUT_OF_STOCK = 'out_of_stock';

    public const NOT_PURCHASABLE = 'not_purchasable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

function restoreSnapshot($data = null) {
    if ($data === null) return null;
    return $data;
}