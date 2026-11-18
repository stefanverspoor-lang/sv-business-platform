<?php

namespace App\Filament\Resources\RecurringInvoices;

use App\Enums\BillingInterval;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\RecurringInvoices\Pages\CreateRecurringInvoice;
use App\Filament\Resources\RecurringInvoices\Pages\EditRecurringInvoice;
use App\Filament\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Filament\Shared\Billing\BillingForm;
use App\Models\RecurringInvoice;
use App\Support\Billing\Billing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecurringInvoiceResource extends Resource
{
    protected static ?string $model = RecurringInvoice::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'terugkerende-facturen';

    public static function getModelLabel(): string
    {
        return __('terugkerende factuur');
    }

    public static function getPluralModelLabel(): string
    {
        return __('terugkerende facturen');
    }

    public static function getNavigationLabel(): string
    {
        return __('Terugkerend');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Facturatie');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label(__('Naam'))->required()->maxLength(255)->placeholder(__('Bijv. Onderhoud website Bakkerij')),
                    ...BillingForm::clientFields(),
                    Select::make('interval')->label(__('Hoe vaak'))->options(BillingInterval::class)->default(BillingInterval::Monthly)->required(),
                    DatePicker::make('next_date')->label(__('Volgende factuurdatum'))->default(now()->startOfMonth()->addMonthNoOverflow())->required(),
                    DatePicker::make('end_date')->label(__('Stoppen na'))->helperText(__('Leeg = doorlopend')),
                    TextInput::make('payment_terms_days')->label(__('Betaaltermijn (dagen)'))->numeric()->minValue(0)->default(14)->required(),
                    ...BillingForm::languageAndCurrency(withRate: false),
                    Toggle::make('auto_finalize')->label(__('Meteen definitief maken'))->live()
                        ->helperText(__('Uit = er komt een concept klaar te staan dat je zelf controleert.')),
                    Toggle::make('auto_send')->label(__('Automatisch mailen naar de klant'))
                        ->visible(fn ($get) => $get('auto_finalize'))
                        ->helperText(__('Via de eerste actieve mailbox, naar de contactpersoon of het e-mailadres van de klant.')),
                    Toggle::make('is_active')->label(__('Actief'))->default(true),
                ]),
            Section::make(__('Regels'))
                ->description(__('Gebruik {periode} in de omschrijving voor de maand of periode, bijv. "Onderhoud website {periode}".'))
                ->columnSpanFull()
                ->schema([BillingForm::linesRepeater(), BillingForm::liveTotals()]),
            Section::make(__('Teksten'))->collapsible()->collapsed()->columnSpanFull()->schema([
                TextInput::make('subject')->label(__('Onderwerp'))->maxLength(255)->placeholder(__('Onderhoud {periode}')),
                Textarea::make('notes')->label(__('Opmerkingen'))->rows(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_date')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'lines']))
            ->columns([
                TextColumn::make('name')->label(__('Naam'))->searchable()->description(fn (RecurringInvoice $r) => $r->client?->name),
                TextColumn::make('interval')->label(__('Hoe vaak')),
                TextColumn::make('amount')->label(__('Bedrag excl. btw'))->alignEnd()
                    ->state(fn (RecurringInvoice $r) => \App\Support\Billing\Money::format($r->lines->sum(fn ($l) => (float) $l->line_total), $r->currency)),
                TextColumn::make('next_date')->label(__('Volgende'))->date('d-m-Y')->sortable(),
                IconColumn::make('auto_finalize')->label(__('Automatisch'))->boolean(),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->recordActions([
                Action::make('generate')
                    ->label(__('Nu factuur maken'))
                    ->icon(Heroicon::OutlinedBolt)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('Maakt nu een conceptfactuur voor de volgende periode en schuift de volgende datum op.'))
                    ->visible(fn () => auth()->user()->can('invoices.manage'))
                    ->action(fn (RecurringInvoice $record, $livewire) => $livewire->redirect(InvoiceResource::getUrl('edit', ['record' => app(Billing::class)->generateFromRecurring($record)]))),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringInvoices::route('/'),
            'create' => CreateRecurringInvoice::route('/create'),
            'edit' => EditRecurringInvoice::route('/{record}/edit'),
        ];
    }
}
