<?php

namespace App\Support\Calling;

/**
 * Zet telefoonnummers om naar internationaal formaat (+31201234567), zodat
 * BEL NU altijd werkt en dubbele nummers herkend worden, hoe ze ook in de
 * Excel van de opdrachtgever staan (020-1234567, 0031 20 ..., +31 (0)20 ...).
 */
class PhoneNumber
{
    /** Landnummers voor nummers zonder landcode. */
    public const COUNTRY_CODES = [
        'NL' => '31', 'BE' => '32', 'DE' => '49', 'FR' => '33', 'GB' => '44', 'LU' => '352',
        'ES' => '34', 'IT' => '39', 'AT' => '43', 'CH' => '41', 'DK' => '45', 'SE' => '46',
        'NO' => '47', 'PL' => '48', 'PT' => '351', 'IE' => '353', 'ID' => '62', 'AU' => '61',
        'NZ' => '64', 'SG' => '65', 'MY' => '60', 'US' => '1', 'CA' => '1', 'SC' => '248',
    ];

    public static function normalize(?string $raw, ?string $defaultCountry = 'NL'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = trim($raw);
        // "+31 (0)20 ..." → de (0) hoort er niet bij
        $value = preg_replace('/\(0\)/', '', $value);
        $plus = str_starts_with(ltrim($value), '+');
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '' || strlen($digits) < 6) {
            return null;
        }

        if ($plus) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        $code = self::COUNTRY_CODES[strtoupper((string) $defaultCountry)] ?? '31';

        // Excel maakt van 0612345678 vaak 612345678: dan ontbreekt de 0.
        if (str_starts_with($digits, '0')) {
            return '+'.$code.substr($digits, 1);
        }
        if (str_starts_with($digits, $code) && strlen($digits) >= strlen($code) + 8) {
            return '+'.$digits;
        }

        return '+'.$code.$digits;
    }

    /** Leesbare weergave, bijvoorbeeld +31 20 123 4567 blijft gewoon het internationale nummer. */
    public static function telLink(?string $normalized): ?string
    {
        return $normalized ? 'tel:'.$normalized : null;
    }

    /** CloudTalk Desktop opent direct met dit nummer. */
    public static function cloudTalkLink(?string $normalized): ?string
    {
        return $normalized ? 'ct+tel:'.$normalized : null;
    }
}
