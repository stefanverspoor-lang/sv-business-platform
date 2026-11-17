<?php

namespace App\Support\Imports;

use App\Enums\CollectionStatus;
use App\Enums\DebtorType;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\DataImport;
use App\Support\Calling\PhoneNumber;

class CollectionFileImporter extends Importer
{
    /** @var array<string, true> */
    protected array $seen = [];

    public function __construct(
        protected Client $client,
        protected ?int $assignTo = null,
        protected int $maxAttempts = 6,
        protected string $currency = 'EUR',
    ) {}

    protected function type(): string
    {
        return DataImport::TYPE_COLLECTION;
    }

    public static function columns(): array
    {
        return [
            'file_number' => ['Dossiernummer', 'Dossier', 'Dossier nr', 'Dossiernr', 'Referentie', 'Klantnummer', 'File number', 'Case number', 'Reference'],
            'debtor_name' => ['Debiteur', 'Debiteurnaam', 'Naam debiteur', 'Naam', 'Debtor', 'Debtor name', 'Name'],
            'debtor_type' => ['Soort debiteur', 'Type debiteur', 'Particulier/Zakelijk', 'Debtor type', 'Type'],
            'contact_name' => ['Contactpersoon', 'Contact', 'Contact name'],
            'phone' => ['Telefoon', 'Telefoonnummer', 'Tel', 'Phone', 'Phone number'],
            'phone_alt' => ['Telefoon 2', 'Mobiel', 'Mobile', 'Phone 2'],
            'email' => ['E-mail', 'Email', 'E-mailadres', 'Email address'],
            'address_line' => ['Adres', 'Straat', 'Address'],
            'postal_code' => ['Postcode', 'Zip', 'Postal code'],
            'city' => ['Plaats', 'Woonplaats', 'City'],
            'country' => ['Land', 'Landcode', 'Country'],
            'invoice_number' => ['Factuurnummer', 'Factuur', 'Factuurnr', 'Invoice', 'Invoice number'],
            'invoice_date' => ['Factuurdatum', 'Invoice date'],
            'due_date' => ['Vervaldatum', 'Vervaldag', 'Due date'],
            'original_amount' => ['Oorspronkelijk bedrag', 'Hoofdsom', 'Factuurbedrag', 'Original amount', 'Invoice amount'],
            'outstanding_amount' => ['Openstaand bedrag', 'Openstaand', 'Bedrag', 'Saldo', 'Outstanding amount', 'Outstanding', 'Amount'],
            'currency' => ['Valuta', 'Munteenheid', 'Currency'],
            'notes' => ['Notities', 'Opmerkingen', 'Opmerking', 'Notes'],
        ];
    }

    public static function required(): array
    {
        return ['file_number', 'debtor_name', 'outstanding_amount'];
    }

    public static function template(): array
    {
        return [
            ['Dossiernummer', 'Debiteur', 'Soort debiteur', 'Contactpersoon', 'Telefoon', 'Telefoon 2', 'E-mail', 'Adres', 'Postcode', 'Plaats', 'Land',
                'Factuurnummer', 'Factuurdatum', 'Vervaldatum', 'Oorspronkelijk bedrag', 'Openstaand bedrag', 'Valuta', 'Notities'],
            ['D-2026-0001', 'J. de Vries', 'Particulier', '', '06 12345678', '', 'j.devries@example.nl', 'Kerkstraat 1', '1234 AB', 'Utrecht', 'NL',
                'F-1001', '01-10-2026', '31-10-2026', '250,00', '250,00', 'EUR', ''],
        ];
    }

    protected function before(DataImport $import): void
    {
        $this->seen = CollectionFile::withTrashed()->where('client_id', $this->client->id)
            ->pluck('file_number')->map(fn ($n) => strtolower($n))->flip()->map(fn () => true)->all();
    }

    protected function importRow(array $data, int $line, DataImport $import): void
    {
        $number = Spreadsheet::text($data['file_number']);
        $key = strtolower($number);
        if (isset($this->seen[$key])) {
            $this->duplicate($line, __('dossier :number bestaat al', ['number' => $number]));

            return;
        }

        $outstanding = Spreadsheet::amount($data['outstanding_amount']);
        if ($outstanding === null || $outstanding <= 0) {
            $this->fail($line, __('openstaand bedrag ontbreekt of is 0'));

            return;
        }

        $country = strtoupper((string) Spreadsheet::text($data['country'] ?? null)) ?: null;
        if ($country && strlen($country) !== 2) {
            $country = null;
        }
        $phone = Spreadsheet::text($data['phone'] ?? null);
        if (! PhoneNumber::normalize($phone, $country ?: ($this->client->country ?: 'NL'))) {
            $this->warn($line, __('dossier :number heeft geen bruikbaar telefoonnummer en komt niet in de belwachtrij', ['number' => $number]));
        }

        $type = strtolower((string) Spreadsheet::text($data['debtor_type'] ?? null));
        $currency = strtoupper((string) Spreadsheet::text($data['currency'] ?? null)) ?: $this->currency;

        CollectionFile::create([
            'client_id' => $this->client->id,
            'file_number' => $number,
            'debtor_type' => str_starts_with($type, 'z') || str_starts_with($type, 'b') ? DebtorType::Business : DebtorType::Private,
            'debtor_name' => Spreadsheet::text($data['debtor_name']),
            'contact_name' => Spreadsheet::text($data['contact_name'] ?? null),
            'phone' => $phone,
            'phone_alt' => Spreadsheet::text($data['phone_alt'] ?? null),
            'email' => strtolower((string) Spreadsheet::text($data['email'] ?? null)) ?: null,
            'address_line' => Spreadsheet::text($data['address_line'] ?? null),
            'postal_code' => Spreadsheet::text($data['postal_code'] ?? null),
            'city' => Spreadsheet::text($data['city'] ?? null),
            'country' => $country,
            'invoice_number' => Spreadsheet::text($data['invoice_number'] ?? null),
            'invoice_date' => Spreadsheet::date($data['invoice_date'] ?? null),
            'due_date' => Spreadsheet::date($data['due_date'] ?? null),
            'original_amount' => Spreadsheet::amount($data['original_amount'] ?? null) ?? $outstanding,
            'outstanding_amount' => $outstanding,
            'currency' => strlen($currency) === 3 ? $currency : $this->currency,
            'notes' => Spreadsheet::text($data['notes'] ?? null),
            'extra' => $data['extra'] ?: null,
            'status' => CollectionStatus::New,
            'assigned_to' => $this->assignTo,
            'max_attempts' => $this->maxAttempts,
            'data_import_id' => $import->id,
        ]);
        $this->seen[$key] = true;
        $this->imported++;
    }
}
