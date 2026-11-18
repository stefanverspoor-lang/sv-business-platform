<?php

namespace App\Support\Billing;

use App\Models\Company;
use App\Models\Setting;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Str;

/** Factuurinstellingen van het actieve bedrijf (opgeslagen in de bedrijfsdatabase). */
class BillingSettings
{
    public static function defaults(?Company $company = null): array
    {
        $company ??= app(TenantManager::class)->current();
        $prefix = Str::upper($company?->slug ?? 'F');

        return [
            'invoice_prefix' => $prefix.'-',
            'credit_prefix' => $prefix.'-CN-',
            'quote_prefix' => $prefix.'-OF-',
            'number_digits' => 4,
            'payment_terms_days' => 14,
            'quote_valid_days' => 30,
            'bank_name' => null,
            'account_holder' => $company?->legal_name ?: $company?->name,
            'iban' => null,
            'bic' => null,
            'logo_path' => null,
            'payment_text_nl' => 'Wij verzoeken u het bedrag van {bedrag} vóór {vervaldatum} over te maken op {iban} t.n.v. {rekeninghouder}, onder vermelding van factuurnummer {nummer}.',
            'payment_text_en' => 'Please transfer {amount} before {due_date} to {iban} in the name of {account_holder}, quoting invoice number {number}.',
            'footer_nl' => null,
            'footer_en' => null,
            'invoice_email_subject_nl' => 'Factuur {nummer} van {bedrijf}',
            'invoice_email_subject_en' => 'Invoice {number} from {company}',
            'invoice_email_body_nl' => "Beste {naam},\n\nIn de bijlage vind je factuur {nummer} van {bedrag}, te voldoen vóór {vervaldatum}.\n\nMet vriendelijke groet,\n{afzender}\n{bedrijf}",
            'invoice_email_body_en' => "Dear {name},\n\nPlease find attached invoice {number} for {amount}, due on {due_date}.\n\nKind regards,\n{sender}\n{company}",
            'quote_email_subject_nl' => 'Offerte {nummer} van {bedrijf}',
            'quote_email_subject_en' => 'Quote {number} from {company}',
            'quote_email_body_nl' => "Beste {naam},\n\nIn de bijlage vind je onze offerte {nummer}. Deze is geldig tot {geldig_tot}.\n\nMet vriendelijke groet,\n{afzender}\n{bedrijf}",
            'quote_email_body_en' => "Dear {name},\n\nPlease find attached our quote {number}, valid until {valid_until}.\n\nKind regards,\n{sender}\n{company}",
        ];
    }

    public static function all(): array
    {
        return Setting::many(static::defaults());
    }

    public static function get(string $key): mixed
    {
        return Setting::get($key, static::defaults()[$key] ?? null);
    }

    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, static::defaults()) as $key => $value) {
            Setting::put($key, $value);
        }
    }

    public static function baseCurrency(): string
    {
        return app(TenantManager::class)->current()?->default_currency ?: 'EUR';
    }

    /** Vervangt {placeholders} (NL en EN namen). */
    public static function fill(string $text, array $values): string
    {
        return strtr($text, collect($values)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());
    }
}
