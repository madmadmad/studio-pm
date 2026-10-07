<?php

namespace App\Support;

use InvalidArgumentException;

// The boundary between the app's decimal amounts ("12.34", as decimal
// columns and decimal:2 casts give them) and the ledger's integer cents.
// Parses the digits rather than multiplying a float by 100, which turns
// 1.005 into 100.4999... and loses a cent.
class Money
{
    // "12.34" → 1234. Rounds half away from zero past the second decimal
    // ("1.005" → 101). Null or blank is zero.
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount)) {
            // Enough places to carry the intended digits, few enough that
            // binary noise (1.00499999...) rounds away.
            $amount = sprintf('%.10F', $amount);
        }

        $amount = trim($amount);
        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("\"{$amount}\" isn't an amount.");
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents = (int) $m[2] * 100 + (int) substr($fraction, 0, 2);
        if ($fraction[2] >= '5') {
            $cents++;
        }

        return $m[1] === '-' ? -$cents : $cents;
    }

    // 1234 → "12.34", -5 → "-0.05": back to a decimal string.
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
