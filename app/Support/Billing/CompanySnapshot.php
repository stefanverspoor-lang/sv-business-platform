<?php

namespace App\Support\Billing;

use App\Models\Company;

/** Bedrijfsgegevens zoals ze op de factuur komen; vastgelegd bij definitief maken. */
class CompanySnapshot
{
    public static function make(?Company $company, ?array $settings = null): array
    {
        $settings ??= BillingSettings::all();

        return [
            'name' => $company?->legal_name ?: $company?->name,
            'trade_name' => $company?->name,
            'address_line' => $company?->address_line,
            'postal_code' => $company?->postal_code,
            'city' => $company?->city,
            'country' => $company?->country,
            'email' => $company?->email,
            'phone' => $company?->phone,
            'website' => $company?->website,
            'registration_number' => $company?->registration_number,
            'vat_number' => $company?->vat_number,
            'bank_name' => $settings['bank_name'] ?? null,
            'account_holder' => $settings['account_holder'] ?? null,
            'iban' => $settings['iban'] ?? null,
            'bic' => $settings['bic'] ?? null,
        ];
    }
}
