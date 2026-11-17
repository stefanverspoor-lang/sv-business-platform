<?php

namespace App\Filament\Resources\CallOutcomes;

use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Enums\OutcomeEffect;
use App\Filament\Resources\CallOutcomes\Pages\ManageCallOutcomes;
use App\Models\CallOutcome;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CallOutcomeResource extends Resource
{
    protected static ?string $model = CallOutcome::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 82;

    protected static ?string $slug = 'gespreksresultaten';

    public static function getModelLabel(): string
    {
        return __('gespreksresultaat');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gespreksresultaten');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function modules(): array
    {
        return [CallOutcome::MODULE_TELEMARKETING => __('Telemarketing'), CallOutcome::MODULE_COLLECTION => __('Pre-incasso')];
    }

    public static function form(Schema $schema): Schema
    {
        $system = fn (?CallOutcome $record) => (bool) $record?->is_system;
        $statusOptions = fn (Get $get) => collect($get('module') === CallOutcome::MODULE_TELEMARKETING ? LeadStatus::cases() : CollectionStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all();

        return $schema->columns(2)->components([
            Select::make('module')->label(__('Module'))->options(static::modules())->required()->live()->disabled($system)->dehydrated(),
            TextInput::make('label')->label(__('Naam'))->required()->maxLength(255),
            TextInput::make('label_en')->label(__('Naam in het Engels'))->maxLength(255),
            Select::make('effect')->label(__('Wat gebeurt er daarna?'))->options(OutcomeEffect::class)->required()->disabled($system)->dehydrated(),
            Select::make('record_status')->label(__('Nieuwe status'))->options($statusOptions)->required()->disabled($system)->dehydrated(),
            Select::make('color')->label(__('Kleur'))->options([
                'gray' => __('Grijs'), 'info' => __('Blauw'), 'success' => __('Groen'), 'warning' => __('Oranje'), 'danger' => __('Rood'),
            ])->default('gray')->required(),
            Toggle::make('counts_as_contact')->label(__('Telt als contact (iemand gesproken)')),
            TextInput::make('sort')->label(__('Volgorde'))->numeric()->default(500),
            Toggle::make('is_active')->label(__('Actief'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->defaultGroup('module')
            ->columns([
                TextColumn::make('label')->label(__('Naam'))->badge()->color(fn (CallOutcome $r) => $r->color),
                TextColumn::make('effect')->label(__('Daarna')),
                TextColumn::make('record_status')->label(__('Nieuwe status'))
                    ->formatStateUsing(fn (CallOutcome $r) => ($r->module === CallOutcome::MODULE_TELEMARKETING
                        ? LeadStatus::tryFrom($r->record_status) : CollectionStatus::tryFrom($r->record_status))?->getLabel()),
                IconColumn::make('is_system')->label(__('Standaard'))->boolean(),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->filters([SelectFilter::make('module')->label(__('Module'))->options(static::modules())])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCallOutcomes::route('/')];
    }

    public static function newCode(string $label): string
    {
        return Str::limit(Str::slug($label, '_'), 30, '').'_'.Str::lower(Str::random(4));
    }
}
