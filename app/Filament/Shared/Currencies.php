<?php

namespace App\Filament\Shared;

/** Gangbare valuta; uitbreidbaar. */
class Currencies
{
    public const CODES = ['EUR', 'USD', 'IDR', 'GBP', 'AUD', 'SGD', 'SCR', 'CHF', 'MYR', 'NZD', 'CAD', 'JPY'];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(self::CODES, self::CODES);
    }
}
