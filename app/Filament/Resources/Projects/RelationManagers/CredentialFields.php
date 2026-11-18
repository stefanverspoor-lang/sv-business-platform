<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Support\Audit\Audit;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Inloggegevens van domein en hosting. Ze staan versleuteld in de database,
 * komen nooit in een formulier terug en worden alleen getoond via de knop
 * "Inloggegevens tonen" (wordt vastgelegd in de audit-log).
 */
class CredentialFields
{
    /** @param  array<string, string>  $fields  veld => label */
    public static function inputs(array $fields, array $multiline = []): array
    {
        return collect($fields)->map(function (string $label, string $field) use ($multiline) {
            $input = in_array($field, $multiline, true)
                ? Textarea::make($field)->rows(3)
                : TextInput::make($field)->maxLength(500);

            if ($field === 'password') {
                $input->password()->revealable();
            }

            return $input->label($label)
                ->dehydrated(fn ($state) => filled($state))
                ->placeholder(fn (?Model $record) => $record && filled($record->{$field}) ? __('•••••• (opgeslagen; leeg laten = niet wijzigen)') : null);
        })->values()->all();
    }

    /** @param  array<string, string>  $fields */
    public static function showAction(array $fields): Action
    {
        return Action::make('showCredentials')
            ->label(__('Inloggegevens'))
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn () => auth()->user()->can('credentials.view') || auth()->user()->can('projects.manage'))
            ->modalHeading(__('Inloggegevens'))
            ->modalDescription(__('Wie dit bekijkt, wordt vastgelegd in de audit-log.'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Sluiten'))
            ->mountUsing(fn (Model $record) => Audit::log('credentials_viewed', $record))
            ->schema(fn (Model $record) => collect($fields)
                ->map(fn (string $label, string $field) => TextEntry::make($field)->label($label)->state($record->{$field})
                    ->placeholder('-')->copyable()->fontFamily('mono'))
                ->values()->all());
    }

    /** @param  array<int, string>  $fields  veldnamen */
    public static function hasAny(Model $record, array $fields): bool
    {
        return collect($fields)->contains(fn ($f) => filled($record->{$f}));
    }
}
