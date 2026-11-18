<?php

namespace App\Filament\Resources\FinanceCategories;

use App\Filament\Resources\FinanceCategories\Pages\ManageFinanceCategories;
use App\Models\FinanceCategory;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FinanceCategoryResource extends Resource
{
    protected static ?string $model = FinanceCategory::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 86;

    protected static ?string $slug = 'categorieen';

    public static function getModelLabel(): string
    {
        return __('categorie');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kosten- en inkomstencategorieën');
    }

    public static function getNavigationLabel(): string
    {
        return __('Categorieën');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function types(): array
    {
        return ['expense' => __('Uitgaven'), 'income' => __('Inkomsten')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label(__('Soort'))->options(static::types())->required(),
            TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
            TextInput::make('sort')->label(__('Volgorde'))->numeric()->default(100),
            Toggle::make('is_active')->label(__('Actief'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->defaultGroup('type')
            ->columns([
                TextColumn::make('name')->label(__('Naam')),
                TextColumn::make('type')->label(__('Soort'))->formatStateUsing(fn ($state) => static::types()[$state] ?? $state),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->filters([SelectFilter::make('type')->label(__('Soort'))->options(static::types())])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFinanceCategories::route('/')];
    }
}
