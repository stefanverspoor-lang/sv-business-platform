<?php

namespace App\Filament\Resources\VatRates;

use App\Filament\Resources\VatRates\Pages\ManageVatRates;
use App\Models\VatRate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Btw-tarieven van dit bedrijf. Wijzigen heeft geen invloed op al gemaakte facturen. */
class VatRateResource extends Resource
{
    protected static ?string $model = VatRate::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 85;

    protected static ?string $slug = 'btw-tarieven';

    public static function getModelLabel(): string
    {
        return __('btw-tarief');
    }

    public static function getPluralModelLabel(): string
    {
        return __('btw-tarieven');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Naam op de factuur'))->required()->maxLength(255),
            TextInput::make('rate')->label(__('Percentage'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
            Select::make('kind')->label(__('Soort'))->options(VatRate::kinds())->required(),
            TextInput::make('sort')->label(__('Volgorde'))->numeric()->default(100),
            TextInput::make('invoice_note')->label(__('Tekst op de factuur (NL)'))->maxLength(255)->placeholder(__('Bijv. Btw verlegd naar de afnemer.')),
            TextInput::make('invoice_note_en')->label(__('Tekst op de factuur (EN)'))->maxLength(255),
            Toggle::make('is_default')->label(__('Standaard voor nieuwe regels')),
            Toggle::make('is_active')->label(__('Actief'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('Naam'))->description(fn (VatRate $r) => $r->invoice_note),
                TextColumn::make('rate')->label(__('Percentage'))->suffix('%'),
                TextColumn::make('kind')->label(__('Soort'))->formatStateUsing(fn ($state) => VatRate::kinds()[$state] ?? $state),
                IconColumn::make('is_default')->label(__('Standaard'))->boolean(),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVatRates::route('/')];
    }
}
