<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

/**
 * Eigen profiel: naam, e-mail, wachtwoord, taal en tijdzone.
 * Tweestapsverificatie beheer je ook op deze pagina.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Select::make('locale')
                ->label(__('Taal'))
                ->options(config('crm.locales'))
                ->required(),
            Select::make('timezone')
                ->label(__('Tijdzone'))
                ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->searchable()
                ->placeholder(__('Automatisch (tijdzone van deze computer)'))
                ->helperText(__('Laat leeg om automatisch de tijdzone van je computer te gebruiken.')),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getRedirectUrl(): ?string
    {
        // Herladen zodat een nieuwe taal direct overal zichtbaar is.
        return static::getUrl();
    }
}
