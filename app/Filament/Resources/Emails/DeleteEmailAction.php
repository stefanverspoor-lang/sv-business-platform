<?php

namespace App\Filament\Resources\Emails;

use App\Models\EmailMessage;
use App\Support\Mail\MessageDeleter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** Verwijderen van e-mail, los en in bulk; standaard ook uit de mailbox. */
class DeleteEmailAction
{
    protected static function form(): array
    {
        return [
            Toggle::make('from_server')->label(__('Ook uit de mailbox verwijderen (naar de prullenbak)'))->default(true)
                ->helperText(__('Uit: alleen uit het CRM. Het bericht blijft dan in je mailbox, maar komt niet opnieuw binnen.')),
        ];
    }

    protected static function notify(array $result): void
    {
        if ($result['server_failed']) {
            Notification::make()->warning()->title(__('Verwijderd uit het CRM'))
                ->body(__('In de mailbox zelf lukte het niet voor :n bericht(en); verwijder die daar eventueel handmatig.', ['n' => $result['server_failed']]))
                ->persistent()->send();

            return;
        }
        Notification::make()->success()->title(trans_choice(':count e-mail verwijderd|:count e-mails verwijderd', $result['deleted'], ['count' => $result['deleted']]))->send();
    }

    public static function make(callable $after): Action
    {
        return Action::make('delete')
            ->label(__('Verwijderen'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize(fn (EmailMessage $record) => auth()->user()->can('delete', $record))
            ->requiresConfirmation()
            ->modalHeading(__('E-mail verwijderen?'))
            ->schema(static::form())
            ->action(function (EmailMessage $record, array $data) use ($after) {
                static::notify(app(MessageDeleter::class)->delete([$record], (bool) ($data['from_server'] ?? true)));
                $after();
            });
    }

    public static function bulk(): BulkAction
    {
        return BulkAction::make('delete')
            ->label(__('Verwijderen'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn () => auth()->user()->can('emails.delete'))
            ->requiresConfirmation()
            ->modalHeading(__('Geselecteerde e-mails verwijderen?'))
            ->schema(static::form())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) {
                $records = $records->filter(fn (EmailMessage $r) => auth()->user()->can('delete', $r));
                static::notify(app(MessageDeleter::class)->delete($records, (bool) ($data['from_server'] ?? true)));
            });
    }
}
