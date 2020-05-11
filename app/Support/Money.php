<?php

namespace App\Support;

/**
 * Integer-cents money helpers. Floats never touch an amount: parsing works on
 * the string digits and formatting splits with intdiv/modulo.
 */
final class Money
{
    public static function format(int $cents, ?string $currency = null): string
    {
        $currency ??= config('marketplace.currency');
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        $amount = number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return match ($currency) {
            'USD' => "{$sign}\${$amount}",
            'EUR' => "{$sign}€{$amount}",
            'GBP' => "{$sign}£{$amount}",
            default => "{$sign}{$amount} {$currency}",
        };
    }

    /** "12", "12.5", "1,299.99", "$4.00" → cents; null if it isn't a plain amount. */
    public static function parse(?string $input): ?int
    {
        $value = str_replace([',', ' ', '$', '€', '£'], '', trim((string) $input));
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** Cents → "12.50" for form inputs. */
    public static function toInput(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function bpsToPercent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
    }
}
