<?php

use App\Support\Money;

if (! function_exists('money')) {
    /** Format integer cents for display, e.g. money(129900) → "$1,299.00". */
    function money(int $cents, ?string $currency = null): string
    {
        return Money::format($cents, $currency);
    }
}
