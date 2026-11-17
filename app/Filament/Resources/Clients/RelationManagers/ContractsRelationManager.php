<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\ContractStatus;
use App\Filament\Shared\Currencies;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Contracten');
    }

    public static function getModelLabel(): string
    {
        return __('contract');
    }

    public static function getPluralModelLabel(): string
    {
        return __('contracten');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label(__('Omschrijving'))->required()->maxLength(255)->columnSpanFull(),
            Select::make('status')->label(__('Status'))->options(ContractStatus::class)->default(ContractStatus::Draft)->required(),
            Toggle::make('auto_renew')->label(__('Stilzwijgend verlengen'))->inline(false),
            DatePicker::make('start_date')->label(__('Ingangsdatum')),
            DatePicker::make('end_date')->label(__('Einddatum'))->afterOrEqual('start_date'),
            TextInput::make('notice_period_days')->label(__('Opzegtermijn (dagen)'))->numeric()->minValue(0),
            TextInput::make('value')->label(__('Waarde'))->numeric()->minValue(0),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default(fn () => \Filament\Facades\Filament::getTenant()->default_currency)->required(),
            Textarea::make('agreements')->label(__('Afspraken'))->rows(4)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Omschrijving'))->wrap(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('start_date')->label(__('Ingang'))->date('d-m-Y')->sortable(),
                TextColumn::make('end_date')->label(__('Einde'))->date('d-m-Y')->sortable(),
                TextColumn::make('value')->label(__('Waarde'))
                    ->formatStateUsing(fn ($state, $record) => $state !== null ? $record->currency.' '.number_format((float) $state, 2, ',', '.') : null),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
