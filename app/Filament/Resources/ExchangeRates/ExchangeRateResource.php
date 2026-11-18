<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Filament\Resources\ExchangeRates\Pages\ManageExchangeRates;
use App\Filament\Shared\Currencies;
use App\Models\ExchangeRate;
use App\Support\Billing\BillingSettings;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Wisselkoersregistratie: koers per valuta per datum, naar de basisvaluta van het bedrijf. */
class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'wisselkoersen';

    public static function getModelLabel(): string
    {
        return __('wisselkoers');
    }

    public static function getPluralModelLabel(): string
    {
        return __('wisselkoersen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Financiën');
    }

    public static function form(Schema $schema): Schema
    {
        $base = fn () => BillingSettings::baseCurrency();

        return $schema->columns(2)->components([
            Select::make('currency')->label(__('Valuta'))->options(fn () => collect(Currencies::options())->except($base()))->required()->live(),
            DatePicker::make('date')->label(__('Datum'))->default(now())->required(),
            TextInput::make('rate')
                ->label(fn (Get $get) => __('1 :cur = ... :base', ['cur' => $get('currency') ?: '...', 'base' => $base()]))
                ->numeric()->minValue(0.00000001)->required()
                ->helperText(__('Bijv. 1 USD = 0,92 EUR, of 1 IDR = 0,0000585 EUR.')),
            TextInput::make('source')->label(__('Bron'))->maxLength(255)->placeholder(__('Bijv. ECB, bank')),
            Hidden::make('base_currency')->default($base),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label(__('Datum'))->date('d-m-Y')->sortable(),
                TextColumn::make('currency')->label(__('Valuta')),
                TextColumn::make('rate')->label(__('Koers'))
                    ->formatStateUsing(fn (ExchangeRate $r) => '1 '.$r->currency.' = '.rtrim(rtrim(number_format((float) $r->rate, 8, ',', '.'), '0'), ',').' '.$r->base_currency),
                TextColumn::make('source')->label(__('Bron'))->placeholder('-'),
            ])
            ->filters([SelectFilter::make('currency')->label(__('Valuta'))->options(fn () => ExchangeRate::query()->distinct()->pluck('currency', 'currency'))])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExchangeRates::route('/')];
    }
}
