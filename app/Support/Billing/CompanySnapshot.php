<?php

namespace App\Support\Billing;

use App\Models\BankAccount;
use App\Models\Company;

/** Bedrijfsgegevens zoals ze op de factuur komen; vastgelegd bij definitief maken. */
class CompanySnapshot
{
    public static function make(?Company $company, ?array $settings = null, ?BankAccount $bank = null): array
    {
        $settings ??= BillingSettings::all();
        // Gekozen rekening, anders de standaardrekening, anders de oude instellingen.
        $bank ??= BankAccount::forCurrency(null);

        return array_merge([
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
            'bank_extra' => null,
        ], $bank ? $bank->snapshot() : []);
    }
}
