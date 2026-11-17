<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\PricingModel;
use App\Enums\ServiceType;
use App\Filament\Shared\Currencies;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Diensten die de klant afneemt, met prijsafspraken. */
class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Diensten en prijzen');
    }

    public static function getModelLabel(): string
    {
        return __('dienst');
    }

    public static function getPluralModelLabel(): string
    {
        return __('diensten');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('service')->label(__('Dienst'))->options(ServiceType::class)->required(),
            TextInput::make('description')->label(__('Omschrijving'))->maxLength(255),
            Select::make('pricing_model')->label(__('Prijsmodel'))->options(PricingModel::class)->required(),
            TextInput::make('price')->label(__('Prijs of percentage'))->numeric()->minValue(0)
                ->helperText(__('Bij commissie: het percentage')),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default(fn () => Filament::getTenant()->default_currency)->required(),
            Select::make('contract_id')->label(__('Contract'))
                ->options(fn () => $this->getOwnerRecord()->contracts()->pluck('title', 'id')),
            DatePicker::make('start_date')->label(__('Vanaf')),
            DatePicker::make('end_date')->label(__('Tot en met'))->afterOrEqual('start_date'),
            Toggle::make('is_active')->label(__('Actief'))->default(true)->inline(false),
            Textarea::make('notes')->label(__('Notities'))->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('service')->label(__('Dienst'))->badge(),
                TextColumn::make('description')->label(__('Omschrijving'))->wrap(),
                TextColumn::make('pricing_model')->label(__('Prijsmodel')),
                TextColumn::make('price')->label(__('Prijs'))
                    ->formatStateUsing(fn ($state, $record) => $state === null ? null : ($record->pricing_model === PricingModel::Commission
                        ? number_format((float) $state, 2, ',', '.').' %'
                        : $record->currency.' '.number_format((float) $state, 2, ',', '.'))),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public function isReadOnly(): bool
    {
        return ! auth()->user()->can('contracts.manage');
    }
}
