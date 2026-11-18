<?php

namespace App\Filament\Resources\Emails\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Emails\DeleteEmailAction;
use App\Filament\Resources\Emails\EmailResource;
use App\Filament\Shared\ComposeEmailAction;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Support\Audit\Audit;
use App\Support\Mail\MessageStore;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/** @property EmailMessage $record */
class ViewEmail extends ViewRecord
{
    protected static string $resource = EmailResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->direction === 'in' && ! $this->record->is_read) {
            $this->record->update(['is_read' => true]);
        }
    }

    public function getTitle(): string
    {
        return $this->record->subject ?: __('(geen onderwerp)');
    }

    /** Wordt aangeroepen vanuit de bijlagenlijst. */
    public function downloadAttachmentAction(): Action
    {
        return Action::make('downloadAttachment')
            ->action(function (array $arguments) {
                $attachment = $this->record->attachments()->findOrFail($arguments['id'] ?? 0);

                return Storage::disk('local')->download($attachment->path, $attachment->filename);
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            ComposeEmailAction::make('reply', replyTo: fn () => $this->record),
            Action::make('linkClient')
                ->label(__('Koppelen aan klant'))
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->schema([
                    Select::make('client_id')->label(__('Klant'))
                        ->options(fn () => Client::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required(),
                ])
                ->action(function (array $data) {
                    $client = Client::query()->visibleTo(auth()->user())->findOrFail($data['client_id']);
                    app(MessageStore::class)->link($this->record, $client);
                    Notification::make()->success()->title(__('Gekoppeld aan :name', ['name' => $client->name]))->send();
                }),
            Action::make('linkProject')
                ->label(__('Koppelen aan project'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->visible(fn () => \App\Models\Project::query()->visibleTo(auth()->user())->exists())
                ->schema([
                    Select::make('project_id')->label(__('Websiteproject'))
                        ->options(fn () => \App\Models\Project::query()->visibleTo(auth()->user())->with('client')->orderBy('name')->get()
                            ->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->client?->name.')']))
                        ->searchable()->required(),
                ])
                ->action(function (array $data) {
                    $project = \App\Models\Project::query()->visibleTo(auth()->user())->findOrFail($data['project_id']);
                    app(MessageStore::class)->link($this->record, $project);
                    Notification::make()->success()->title(__('Gekoppeld aan :name', ['name' => $project->name]))->send();
                }),
            Action::make('assign')
                ->label(__('Toewijzen'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('emails.view_all'))
                ->fillForm(fn () => ['assigned_to' => $this->record->assigned_to])
                ->schema([
                    Select::make('assigned_to')->label(__('Toewijzen aan'))
                        ->options(fn () => ClientResource::companyUserOptions())->searchable(),
                ])
                ->action(function (array $data) {
                    $this->record->update(['assigned_to' => $data['assigned_to'] ?: null]);
                    Audit::log('email_assigned', $this->record, ['assigned_to' => $data['assigned_to']]);
                    Notification::make()->success()->title(__('Toegewezen'))->send();
                }),
            Action::make('notSpam')
                ->label(__('Geen spam'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->visible(fn () => $this->record->is_spam)
                ->action(function () {
                    $this->record->update(['is_spam' => false]);
                    Notification::make()->success()->title(__('Verplaatst naar Postvak IN'))->send();
                }),
            DeleteEmailAction::make(fn () => $this->redirect(EmailResource::getUrl('index'))),
            ActionGroup::make([
                Action::make('markSpam')
                    ->label(__('Markeren als spam'))
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->visible(fn () => $this->record->direction === 'in' && ! $this->record->is_spam)
                    ->action(function () {
                        $this->record->update(['is_spam' => true]);
                        $this->redirect(EmailResource::getUrl('index'));
                    }),
                Action::make('markUnread')
                    ->label(__('Markeren als ongelezen'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn () => $this->record->direction === 'in')
                    ->action(function () {
                        $this->record->update(['is_read' => false]);
                        $this->redirect(EmailResource::getUrl('index'));
                    }),
            ]),
        ];
    }
}
