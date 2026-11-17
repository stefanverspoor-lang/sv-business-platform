<?php

namespace App\Filament\Resources\CommissionModels;

use App\Enums\CommissionType;
use App\Filament\Resources\CommissionModels\Pages\ManageCommissionModels;
use App\Filament\Shared\Currencies;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\CommissionModel;
use App\Support\Imports\PaymentImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommissionModelResource extends Resource
{
    protected static ?string $model = CommissionModel::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'commissiemodellen';

    public static function getModelLabel(): string
    {
        return __('commissiemodel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('commissiemodellen');
    }

    public static function getNavigationLabel(): string
    {
        return __('Commissie');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Pre-incasso');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('client_id')->label(__('Opdrachtgever'))
                ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
            TextInput::make('name')->label(__('Naam'))->required()->maxLength(255)->placeholder(__('bijv. 15% van betaald bedrag')),
            Select::make('type')->label(__('Berekening'))->options(CommissionType::class)->required()->live(),
            TextInput::make('rate')
                ->label(fn (Get $get) => $get('type') === CommissionType::FixedPerSuccess->value ? __('Bedrag per geslaagd dossier') : __('Percentage'))
                ->suffix(fn (Get $get) => $get('type') === CommissionType::FixedPerSuccess->value ? null : '%')
                ->numeric()->minValue(0)->required(),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default('EUR')->required(),
            Toggle::make('is_active')->label(__('Actief'))->default(true)->inline(false),
            DatePicker::make('valid_from')->label(__('Geldig vanaf'))->helperText(__('Leeg = altijd')),
            DatePicker::make('valid_until')->label(__('Geldig tot en met')),
            Textarea::make('notes')->label(__('Afspraken / toelichting'))->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('client'))
            ->columns([
                TextColumn::make('client.name')->label(__('Opdrachtgever'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('Naam'))->description(fn (CommissionModel $r) => $r->type->getLabel()),
                TextColumn::make('rate')->label(__('Tarief'))
                    ->formatStateUsing(fn (CommissionModel $r) => $r->type === CommissionType::FixedPerSuccess
                        ? $r->currency.' '.number_format((float) $r->rate, 2, ',', '.')
                        : rtrim(rtrim(number_format((float) $r->rate, 4, ',', '.'), '0'), ',').'%'),
                TextColumn::make('valid_from')->label(__('Vanaf'))->date('d-m-Y')->placeholder('-'),
                TextColumn::make('valid_until')->label(__('Tot'))->date('d-m-Y')->placeholder('-'),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->recordActions([
                Action::make('recalculate')
                    ->label(__('Herberekenen'))
                    ->icon(Heroicon::OutlinedCalculator)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('Berekent de commissie opnieuw voor alle dossiers van deze opdrachtgever waarvan een betaling is gemeld.'))
                    ->action(function (CommissionModel $record) {
                        $count = 0;
                        CollectionFile::where('client_id', $record->client_id)->whereHas('paymentResults')->each(function ($file) use (&$count) {
                            PaymentImporter::evaluate($file);
                            $count++;
                        });
                        Notification::make()->success()->title(trans_choice(':count dossier herberekend|:count dossiers herberekend', $count, ['count' => $count]))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommissionModels::route('/')];
    }
}
