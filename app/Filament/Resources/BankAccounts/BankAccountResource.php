<?php

namespace App\Filament\Resources\BankAccounts;

use App\Filament\Resources\BankAccounts\Pages\ManageBankAccounts;
use App\Filament\Shared\Currencies;
use App\Models\BankAccount;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Bankrekeningen van dit bedrijf. Per factuur kies je welke rekening erop
 * komt; bij een valuta wordt de rekening in die valuta voorgesteld.
 * Wijzigen heeft geen invloed op al definitieve facturen.
 */
class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'bankrekeningen';

    public static function getModelLabel(): string
    {
        return __('bankrekening');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bankrekeningen');
    }

    public static function getNavigationLabel(): string
    {
        return __('Bankrekeningen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Facturatie');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Naam (alleen voor jezelf)'))->placeholder(__('Bijv. Wise EUR – Europa'))->required()->maxLength(255),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->placeholder(__('Alle valuta'))
                ->helperText(__('Bij een factuur in deze valuta wordt deze rekening voorgesteld.')),
            TextInput::make('account_holder')->label(__('Rekeninghouder'))->maxLength(255),
            TextInput::make('bank_name')->label(__('Bank'))->maxLength(255),
            TextInput::make('iban')->label(__('IBAN / rekeningnummer'))->required()->maxLength(50),
            TextInput::make('bic')->label(__('BIC / SWIFT'))->maxLength(20),
            Textarea::make('extra')->label(__('Extra regels op de factuur'))->rows(3)->columnSpanFull()
                ->placeholder(__("Bijv. adres van de bank, routing number of sort code")),
            TextInput::make('sort')->label(__('Volgorde'))->numeric()->default(100),
            Toggle::make('is_default')->label(__('Standaardrekening')),
            Toggle::make('is_active')->label(__('Actief'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('Naam'))->description(fn (BankAccount $r) => $r->bank_name),
                TextColumn::make('iban')->label(__('IBAN / rekeningnummer'))->description(fn (BankAccount $r) => $r->bic),
                TextColumn::make('currency')->label(__('Valuta'))->placeholder(__('Alle')),
                IconColumn::make('is_default')->label(__('Standaard'))->boolean(),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->emptyStateHeading(__('Nog geen bankrekeningen'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (BankAccount $r) => ! \App\Models\Invoice::where('bank_account_id', $r->id)->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBankAccounts::route('/')];
    }
}
