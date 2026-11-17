<?php

namespace App\Filament\Resources\CollectionFiles\Pages;

use App\Filament\Pages\CallScreen;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\CollectionFiles\CollectionFileResource;
use App\Filament\Shared\Calling\CallingActions;
use App\Filament\Shared\Currencies;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Support\Imports\CollectionFileImporter;
use App\Support\Imports\PaymentImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;

class ListCollectionFiles extends ListRecords
{
    protected static string $resource = CollectionFileResource::class;

    protected function clientSelect(): Select
    {
        return Select::make('client_id')->label(__('Opdrachtgever'))
            ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
            ->searchable()->required();
    }

    protected function getHeaderActions(): array
    {
        $canManage = auth()->user()->can('collection.manage');

        return [
            Action::make('startCalling')
                ->label(__('Start met bellen'))
                ->icon(Heroicon::Phone)
                ->color('success')
                ->visible(fn () => auth()->user()->can('calls.make'))
                ->url(fn () => CallScreen::getUrl(['type' => 'collection'])),
            CallingActions::import('importFiles', __('Dossiers importeren'), [
                $this->clientSelect(),
                Select::make('assigned_to')->label(__('Direct toewijzen aan'))
                    ->options(fn () => ClientResource::companyUserOptions())->placeholder(__('Nog niet toewijzen')),
                TextInput::make('max_attempts')->label(__('Maximum belpogingen per dossier'))->numeric()->minValue(1)->default(6)->required(),
                Select::make('currency')->label(__('Valuta (als het bestand die niet noemt)'))->options(Currencies::options())->default('EUR')->required(),
            ], fn (array $data, string $path, string $name) => (new CollectionFileImporter(
                Client::findOrFail($data['client_id']), $data['assigned_to'] ?? null, (int) $data['max_attempts'], $data['currency'],
            ))->run($path, $name, ['client_id' => $data['client_id']]))
                ->visible($canManage),
            CallingActions::import('importPayments', __('Betaalimport'), [
                $this->clientSelect(),
                Toggle::make('mark_missing_unpaid')->label(__('Dossiers met een afspraak die niet in dit bestand staan op "niet betaald" zetten'))
                    ->helperText(__('Alleen aanzetten als de opdrachtgever een volledig overzicht stuurt.')),
            ], fn (array $data, string $path, string $name) => (new PaymentImporter(
                Client::findOrFail($data['client_id']), (bool) ($data['mark_missing_unpaid'] ?? false),
            ))->run($path, $name, ['client_id' => $data['client_id']]))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->modalDescription(__('Het bestand van de opdrachtgever met betaalde dossiers. Een dossier is pas geslaagd als het volledige afgesproken bedrag betaald is; dan wordt ook de commissie berekend.'))
                ->visible($canManage),
            ActionGroup::make([
                CallingActions::downloadTemplate(CollectionFileImporter::class, __('voorbeeld-dossiers')),
                CallingActions::downloadTemplate(PaymentImporter::class, __('voorbeeld-betaalimport')),
                Action::make('export')
                    ->label(__('Dossiers exporteren'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->schema([$this->clientSelect()])
                    ->action(fn (array $data) => $this->export((int) $data['client_id'])),
            ])->visible($canManage),
        ];
    }

    protected function export(int $clientId)
    {
        $client = Client::findOrFail($clientId);
        $tz = FilamentTimezone::get();

        return CallingActions::exportResponse(
            str($client->name)->slug().'-dossiers',
            [__('Dossiernummer'), __('Debiteur'), __('Telefoon'), __('E-mail'), __('Factuurnummer'), __('Openstaand bedrag'), __('Valuta'),
                __('Status'), __('Belpogingen'), __('Laatste resultaat'), __('Laatst gebeld'), __('Afgesproken bedrag'), __('Termijnen'),
                __('Eerste betaaldatum'), __('Betaling'), __('Betaald bedrag'), __('Commissie'), __('Toegewezen aan')],
            CollectionFile::where('client_id', $clientId)->with(['lastOutcome', 'activeAgreement'])->orderBy('file_number')->lazy()->map(fn (CollectionFile $f) => [
                $f->file_number, $f->debtor_name, $f->phone_normalized ?: $f->phone, $f->email, $f->invoice_number,
                (float) $f->outstanding_amount, $f->currency, $f->status, $f->attempts, $f->lastOutcome?->displayLabel(),
                $f->last_called_at?->timezone($tz)->format('d-m-Y H:i'),
                $f->activeAgreement ? (float) $f->activeAgreement->total_amount : null,
                $f->activeAgreement?->installments, $f->activeAgreement?->first_payment_date?->format('d-m-Y'),
                $f->payment_status, (float) $f->paid_amount, $f->commission_amount !== null ? (float) $f->commission_amount : null,
                $f->assigneeName(),
            ]),
        );
    }
}
