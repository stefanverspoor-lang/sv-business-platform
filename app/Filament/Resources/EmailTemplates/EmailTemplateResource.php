<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Models\EmailTemplate;
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

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'email-templates';

    public static function getModelLabel(): string
    {
        return __('e-mailsjabloon');
    }

    public static function getPluralModelLabel(): string
    {
        return __('e-mailsjablonen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
            Select::make('locale')->label(__('Taal'))->options(config('crm.locales'))->default('nl')->required(),
            TextInput::make('subject')->label(__('Onderwerp'))->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('body')->label(__('Tekst'))->rows(10)->required()->columnSpanFull()
                ->helperText(__('Plaatshouders: :list', ['list' => implode(' ', EmailTemplate::PLACEHOLDERS)])),
            Toggle::make('is_active')->label(__('Actief'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Naam'))->searchable()->description(fn (EmailTemplate $r) => $r->subject),
                TextColumn::make('locale')->label(__('Taal'))->badge(),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmailTemplates::route('/')];
    }
}
