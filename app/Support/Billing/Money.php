<?php

namespace App\Support\Billing;

/** Bedragen tonen met valutacode, in Nederlandse of Engelse notatie. */
class Money
{
    public const ZERO_DECIMALS = ['IDR', 'JPY', 'KRW', 'VND'];

    public static function format(float|string|null $amount, string $currency = 'EUR', string $locale = 'nl'): string
    {
        $decimals = in_array(strtoupper($currency), self::ZERO_DECIMALS, true) ? 0 : 2;
        [$dec, $thousands] = $locale === 'en' ? ['.', ','] : [',', '.'];

        return strtoupper($currency).' '.number_format((float) $amount, $decimals, $dec, $thousands);
    }

    public static function number(float|string|null $value, int $decimals = 2, string $locale = 'nl'): string
    {
        [$dec, $thousands] = $locale === 'en' ? ['.', ','] : [',', '.'];
        $s = number_format((float) $value, $decimals, $dec, $thousands);

        // 2,000 → 2 ; 1,500 → 1,5
        return str_contains($s, $dec) ? rtrim(rtrim($s, '0'), $dec) : $s;
    }
}
