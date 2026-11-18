<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\PaymentsRelationManager;
use App\Filament\Shared\Billing\BillingForm;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Billing\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $slug = 'facturen';

    public static function getModelLabel(): string
    {
        return __('factuur');
    }

    public static function getPluralModelLabel(): string
    {
        return __('facturen');
    }

    public static function getNavigationLabel(): string
    {
        return __('Facturen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Facturatie');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('invoices.view')) {
            return null;
        }
        $count = Invoice::unpaid()->whereDate('due_date', '<', today())->count();

        return $count ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Te laat betaald');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull()
                ->schema([
                    ...BillingForm::clientFields(),
                    TextInput::make('reference')->label(__('Referentie klant'))->maxLength(255)->helperText(__('Bijv. inkoopnummer')),
                    DatePicker::make('issue_date')->label(__('Factuurdatum'))->default(now())->required()->live()
                        ->afterStateUpdated(fn ($state, Get $get, Set $set) => $state ? $set('due_date', Carbon::parse($state)->addDays((int) $get('payment_terms_days'))->toDateString()) : null),
                    TextInput::make('payment_terms_days')->label(__('Betaaltermijn (dagen)'))->numeric()->minValue(0)->default(14)->required()->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Get $get, Set $set) => $get('issue_date') ? $set('due_date', Carbon::parse($get('issue_date'))->addDays((int) $state)->toDateString()) : null)
                        ->visible(fn (Get $get, ?Invoice $record) => ! $record?->isCreditNote()),
                    DatePicker::make('due_date')->label(__('Vervaldatum'))->visible(fn (?Invoice $record) => ! $record?->isCreditNote()),
                    ...BillingForm::languageAndCurrency(),
                ]),
            Section::make(__('Regels'))
                ->columnSpanFull()
                ->schema([BillingForm::linesRepeater(), BillingForm::liveTotals()]),
            BillingForm::textFields()->columnSpanFull(),
        ]);
    }

    public static function money(Invoice $r, $amount): string
    {
        return Money::format($amount, $r->currency, app()->getLocale());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('client.name')->label(__('Klant'))->url(fn (Invoice $r) => ClientResource::getUrl('view', ['record' => $r->client_id])),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('type')->label(__('Soort'))->badge(),
                    TextEntry::make('issue_date')->label(__('Factuurdatum'))->date('d-m-Y'),
                    TextEntry::make('due_date')->label(__('Vervaldatum'))->date('d-m-Y')->placeholder('-')
                        ->color(fn (Invoice $r) => $r->isOverdue() ? 'danger' : null),
                    TextEntry::make('total')->label(__('Totaal'))->state(fn (Invoice $r) => static::money($r, $r->total))->weight('bold'),
                    TextEntry::make('paid_total')->label(__('Betaald'))->state(fn (Invoice $r) => static::money($r, $r->paid_total)),
                    TextEntry::make('outstanding')->label(__('Nog te ontvangen'))->state(fn (Invoice $r) => static::money($r, $r->outstanding()))
                        ->color(fn (Invoice $r) => $r->isOverdue() ? 'danger' : null),
                    TextEntry::make('locale')->label(__('Taal'))->formatStateUsing(fn ($state) => $state === 'en' ? 'English' : 'Nederlands'),
                    TextEntry::make('currency')->label(__('Valuta'))
                        ->formatStateUsing(fn (Invoice $r) => $r->currency.($r->currency !== $r->base_currency ? ' (1 = '.rtrim(rtrim(number_format((float) $r->exchange_rate, 6, ',', '.'), '0'), ',').' '.$r->base_currency.')' : '')),
                    TextEntry::make('sent_at')->label(__('Verstuurd'))->dateTime('d-m-Y H:i')->placeholder(__('Nog niet')),
                    TextEntry::make('creditedInvoice.number')->label(__('Correctie op'))->placeholder('-')
                        ->url(fn (Invoice $r) => $r->credited_invoice_id ? static::getUrl('view', ['record' => $r->credited_invoice_id]) : null)
                        ->visible(fn (Invoice $r) => $r->isCreditNote()),
                    TextEntry::make('reference')->label(__('Referentie klant'))->placeholder('-'),
                    TextEntry::make('subject')->label(__('Onderwerp'))->placeholder('-')->columnSpan(2),
                ]),
            Section::make(__('Regels'))
                ->columnSpanFull()
                ->schema([Html::make(fn (Invoice $record) => LinesTable::html($record))]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client'))
            ->columns([
                TextColumn::make('number')->label(__('Nummer'))->searchable()->sortable()
                    ->formatStateUsing(fn (Invoice $r) => $r->displayNumber())->default('-')
                    ->description(fn (Invoice $r) => $r->isCreditNote() ? __('Creditnota') : null),
                TextColumn::make('client.name')->label(__('Klant'))->searchable(),
                TextColumn::make('issue_date')->label(__('Datum'))->date('d-m-Y')->sortable(),
                TextColumn::make('due_date')->label(__('Vervaldatum'))->date('d-m-Y')->sortable()->placeholder('-')
                    ->color(fn (Invoice $r) => $r->isOverdue() ? 'danger' : null),
                TextColumn::make('total')->label(__('Totaal'))->alignEnd()->sortable()->formatStateUsing(fn (Invoice $r) => static::money($r, $r->total)),
                TextColumn::make('outstanding')->label(__('Open'))->alignEnd()
                    ->state(fn (Invoice $r) => $r->isDraft() ? null : $r->outstanding())
                    ->formatStateUsing(fn (Invoice $r, $state) => $state ? static::money($r, $state) : '-'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (Invoice $r) => $r->isOverdue() ? __('Te laat') : $r->status->getLabel())
                    ->color(fn (Invoice $r) => $r->isOverdue() ? 'danger' : $r->status->getColor()),
            ])
            ->filters([
                SelectFilter::make('client_id')->label(__('Klant'))
                    ->options(fn () => Client::query()->whereIn('id', Invoice::query()->select('client_id'))->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('type')->label(__('Soort'))->options(InvoiceType::class),
                SelectFilter::make('currency')->label(__('Valuta'))->options(fn () => Invoice::query()->distinct()->pluck('currency', 'currency')),
                Filter::make('period')
                    ->schema([DatePicker::make('from')->label(__('Van')), DatePicker::make('until')->label(__('Tot en met'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))),
            ])
            ->recordUrl(fn (Invoice $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class, \App\Filament\Resources\Clients\RelationManagers\EmailsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }
}
