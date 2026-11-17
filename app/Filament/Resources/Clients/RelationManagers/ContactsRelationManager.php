<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Contactpersonen');
    }

    public static function getModelLabel(): string
    {
        return __('contactpersoon');
    }

    public static function getPluralModelLabel(): string
    {
        return __('contactpersonen');
    }

    public function isReadOnly(): bool
    {
        return ! auth()->user()->can('update', $this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('first_name')->label(__('Voornaam'))->required()->maxLength(100),
            TextInput::make('last_name')->label(__('Achternaam'))->maxLength(100),
            TextInput::make('job_title')->label(__('Functie'))->maxLength(100),
            TextInput::make('email')->label(__('E-mailadres'))->email()->maxLength(255),
            TextInput::make('phone')->label(__('Telefoonnummer'))->tel()->maxLength(40),
            TextInput::make('mobile')->label(__('Mobiel'))->tel()->maxLength(40),
            Select::make('preferred_locale')->label(__('Taal'))->options(config('crm.locales')),
            Toggle::make('is_primary')->label(__('Hoofdcontactpersoon'))->inline(false),
            Textarea::make('notes')->label(__('Notities'))->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('first_name')
            ->columns([
                TextColumn::make('first_name')->label(__('Naam'))
                    ->formatStateUsing(fn ($record) => $record->full_name)->searchable(['first_name', 'last_name']),
                TextColumn::make('job_title')->label(__('Functie')),
                TextColumn::make('phone')->label(__('Telefoon')),
                TextColumn::make('email')->label(__('E-mail')),
                IconColumn::make('is_primary')->label(__('Hoofdcontact'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
