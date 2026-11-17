<?php

namespace App\Support\Imports;

use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\DataImport;
use App\Models\Lead;
use App\Support\Calling\PhoneNumber;

class LeadImporter extends Importer
{
    /** @var array<string, true> nummers die al in de campagne (of eerder in het bestand) staan */
    protected array $seen = [];

    public function __construct(protected Campaign $campaign, protected ?int $assignTo = null) {}

    protected function type(): string
    {
        return DataImport::TYPE_LEADS;
    }

    public static function columns(): array
    {
        return [
            'company_name' => ['Bedrijfsnaam', 'Bedrijf', 'Organisatie', 'Company', 'Company name', 'Business name'],
            'contact_name' => ['Contactpersoon', 'Naam', 'Contact', 'Contact name', 'Name', 'Full name'],
            'phone' => ['Telefoon', 'Telefoonnummer', 'Tel', 'Phone', 'Phone number', 'Telephone'],
            'phone_alt' => ['Telefoon 2', 'Mobiel', 'Mobiel nummer', 'Mobile', 'Phone 2', 'Alternative phone'],
            'email' => ['E-mail', 'Email', 'E-mailadres', 'Emailadres', 'Email address'],
            'address_line' => ['Adres', 'Straat', 'Address', 'Street'],
            'postal_code' => ['Postcode', 'Zip', 'Zip code', 'Postal code', 'Postcode/zip'],
            'city' => ['Plaats', 'Woonplaats', 'Stad', 'City', 'Town'],
            'country' => ['Land', 'Landcode', 'Country', 'Country code'],
            'website' => ['Website', 'Url', 'Site'],
            'notes' => ['Notities', 'Notitie', 'Opmerkingen', 'Opmerking', 'Notes', 'Comments'],
        ];
    }

    public static function required(): array
    {
        return ['phone'];
    }

    public static function template(): array
    {
        return [
            ['Bedrijfsnaam', 'Contactpersoon', 'Telefoon', 'Telefoon 2', 'E-mail', 'Adres', 'Postcode', 'Plaats', 'Land', 'Website', 'Notities'],
            ['Voorbeeld BV', 'Jan Jansen', '020 123 4567', '06 12345678', 'jan@voorbeeld.nl', 'Damrak 1', '1012 LG', 'Amsterdam', 'NL', 'voorbeeld.nl', 'Eigen kolommen worden als extra informatie bewaard'],
        ];
    }

    protected function before(DataImport $import): void
    {
        $this->seen = $this->campaign->leads()->withTrashed()->whereNotNull('phone_normalized')
            ->pluck('phone_normalized')->flip()->map(fn () => true)->all();
    }

    protected function importRow(array $data, int $line, DataImport $import): void
    {
        $country = strtoupper((string) Spreadsheet::text($data['country'] ?? null)) ?: null;
        if ($country && strlen($country) !== 2) {
            $country = null;
        }
        $phone = Spreadsheet::text($data['phone']);
        $normalized = PhoneNumber::normalize($phone, $country ?: $this->campaign->default_country);

        if (! $normalized) {
            $this->fail($line, __('ongeldig telefoonnummer ":phone"', ['phone' => $phone]));

            return;
        }
        if (isset($this->seen[$normalized])) {
            $this->duplicate($line, __(':phone staat al in deze campagne', ['phone' => $normalized]));

            return;
        }
        $this->seen[$normalized] = true;

        Lead::create([
            'campaign_id' => $this->campaign->id,
            'company_name' => Spreadsheet::text($data['company_name'] ?? null),
            'contact_name' => Spreadsheet::text($data['contact_name'] ?? null),
            'phone' => $phone,
            'phone_alt' => Spreadsheet::text($data['phone_alt'] ?? null),
            'email' => strtolower((string) Spreadsheet::text($data['email'] ?? null)) ?: null,
            'address_line' => Spreadsheet::text($data['address_line'] ?? null),
            'postal_code' => Spreadsheet::text($data['postal_code'] ?? null),
            'city' => Spreadsheet::text($data['city'] ?? null),
            'country' => $country,
            'website' => Spreadsheet::text($data['website'] ?? null),
            'notes' => Spreadsheet::text($data['notes'] ?? null),
            'extra' => $data['extra'] ?: null,
            'status' => LeadStatus::New,
            'assigned_to' => $this->assignTo,
            'data_import_id' => $import->id,
        ]);
        $this->imported++;
    }
}
