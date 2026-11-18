<?php

namespace App\Filament\Resources\Quotes;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\LinesTable;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Shared\Billing\BillingForm;
use App\Models\Quote;
use App\Support\Billing\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $slug = 'offertes';

    public static function getModelLabel(): string
    {
        return __('offerte');
    }

    public static function getPluralModelLabel(): string
    {
        return __('offertes');
    }

    public static function getNavigationLabel(): string
    {
        return __('Offertes');
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
                    ...BillingForm::clientFields(),
                    DatePicker::make('issue_date')->label(__('Datum'))->default(now())->required(),
                    DatePicker::make('valid_until')->label(__('Geldig tot')),
                    ...BillingForm::languageAndCurrency(withRate: false),
                ]),
            Section::make(__('Regels'))->columnSpanFull()->schema([BillingForm::linesRepeater(), BillingForm::liveTotals()]),
            BillingForm::textFields()->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('client.name')->label(__('Klant'))->url(fn (Quote $r) => ClientResource::getUrl('view', ['record' => $r->client_id])),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('issue_date')->label(__('Datum'))->date('d-m-Y'),
                    TextEntry::make('valid_until')->label(__('Geldig tot'))->date('d-m-Y')->placeholder('-'),
                    TextEntry::make('total')->label(__('Totaal'))->state(fn (Quote $r) => Money::format($r->total, $r->currency))->weight('bold'),
                    TextEntry::make('sent_at')->label(__('Verstuurd'))->dateTime('d-m-Y H:i')->placeholder(__('Nog niet')),
                    TextEntry::make('invoice.number')->label(__('Factuur'))->placeholder('-')
                        ->formatStateUsing(fn (Quote $r) => $r->invoice?->displayNumber())
                        ->url(fn (Quote $r) => $r->invoice_id ? InvoiceResource::getUrl('view', ['record' => $r->invoice_id]) : null),
                    TextEntry::make('subject')->label(__('Onderwerp'))->placeholder('-'),
                ]),
            Section::make(__('Regels'))->columnSpanFull()->schema([Html::make(fn (Quote $record) => LinesTable::html($record))]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client'))
            ->columns([
                TextColumn::make('number')->label(__('Nummer'))->searchable()->formatStateUsing(fn (Quote $r) => $r->displayNumber())->default('-'),
                TextColumn::make('client.name')->label(__('Klant'))->searchable(),
                TextColumn::make('subject')->label(__('Onderwerp'))->limit(40)->placeholder('-'),
                TextColumn::make('issue_date')->label(__('Datum'))->date('d-m-Y')->sortable(),
                TextColumn::make('valid_until')->label(__('Geldig tot'))->date('d-m-Y')->placeholder('-'),
                TextColumn::make('total')->label(__('Totaal'))->alignEnd()->formatStateUsing(fn (Quote $r) => Money::format($r->total, $r->currency)),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(QuoteStatus::class)->multiple()])
            ->recordUrl(fn (Quote $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
