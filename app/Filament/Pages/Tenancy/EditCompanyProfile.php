<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Bedrijfsgegevens van SV of YES. Inschrijfnummer en btw-nummer blijven leeg
 * tot het vestigingsland bekend is.
 */
class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('Bedrijfsgegevens');
    }

    public static function canView($tenant): bool
    {
        $user = auth()->user();

        return $user?->isSuperadmin() || $user?->can('users.manage');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Algemeen'))->columns(2)->schema([
                TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
                TextInput::make('legal_name')->label(__('Statutaire naam'))->maxLength(255),
                Select::make('default_currency')->label(__('Standaardvaluta'))
                    ->options(['EUR' => 'EUR', 'USD' => 'USD', 'IDR' => 'IDR', 'GBP' => 'GBP', 'AUD' => 'AUD', 'SGD' => 'SGD', 'SCR' => 'SCR'])
                    ->required(),
                Select::make('default_locale')->label(__('Standaardtaal'))->options(config('crm.locales'))->required(),
                Select::make('timezone')->label(__('Tijdzone'))
                    ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->searchable()->required(),
                ColorPicker::make('primary_color')->label(__('Huisstijlkleur')),
            ]),
            Section::make(__('Registratie'))->columns(2)
                ->description(__('In te vullen zodra het vestigingsland bekend is.'))
                ->schema([
                    TextInput::make('country')->label(__('Landcode'))->maxLength(2),
                    TextInput::make('registration_number')->label(__('Inschrijfnummer')),
                    TextInput::make('vat_number')->label(__('Btw-nummer')),
                ]),
            Section::make(__('Contact'))->columns(2)->schema([
                TextInput::make('address_line')->label(__('Adres')),
                TextInput::make('postal_code')->label(__('Postcode')),
                TextInput::make('city')->label(__('Plaats')),
                TextInput::make('email')->label(__('E-mailadres'))->email(),
                TextInput::make('phone')->label(__('Telefoonnummer'))->tel(),
                TextInput::make('website')->label(__('Website'))->url(),
            ]),
        ]);
    }
}
