<?php

namespace App\Filament\Shared;

use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Mailbox;
use App\Support\Mail\EmailSender;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Knop "E-mail opstellen" / "Beantwoorden". Verstuurt via de gekozen mailbox,
 * met sjablonen, bijlagen en koppeling aan de klant.
 *
 * @param  Closure(): ?Client  $client
 * @param  Closure(): ?EmailMessage  $replyTo
 */
class ComposeEmailAction
{
    public static function make(string $name = 'compose', ?Closure $client = null, ?Closure $replyTo = null): Action
    {
        $client ??= fn () => null;
        $replyTo ??= fn () => null;

        return Action::make($name)
            ->label(fn () => $replyTo() ? __('Beantwoorden') : __('E-mail opstellen'))
            ->icon(fn () => $replyTo() ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedPencilSquare)
            ->visible(fn () => auth()->user()->can('emails.send') && Mailbox::where('is_active', true)->exists())
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Versturen'))
            ->fillForm(function () use ($client, $replyTo) {
                $reply = $replyTo();
                $c = $client() ?? $reply?->linkedClients()->first();
                $to = $reply
                    ? ($reply->direction === 'in' ? [$reply->from_email] : collect($reply->to)->pluck('email')->all())
                    : array_values(array_filter([$c?->primaryContact?->email ?? $c?->email]));

                return [
                    'mailbox_id' => $reply?->mailbox_id ?? Mailbox::where('is_active', true)->orderBy('id')->value('id'),
                    'to' => array_values(array_filter($to)),
                    'subject' => $reply ? (Str::startsWith(Str::lower((string) $reply->subject), 're:') ? $reply->subject : 'Re: '.$reply->subject) : null,
                    'body' => null,
                    'signature' => true,
                    'include_quote' => true,
                    'client_id' => $c?->getKey(),
                ];
            })
            ->schema(fn () => [
                Select::make('mailbox_id')->label(__('Versturen vanaf'))->live()
                    ->options(fn () => Mailbox::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->name} <{$m->email}>"]))
                    ->required(),
                TagsInput::make('to')->label(__('Aan'))->placeholder(__('E-mailadres + Enter'))
                    ->nestedRecursiveRules(['email'])->required(),
                TagsInput::make('cc')->label(__('Cc'))->nestedRecursiveRules(['email']),
                Select::make('client_id')->label(__('Koppelen aan klant'))
                    ->options(fn () => Client::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Select::make('template_id')->label(__('Sjabloon'))
                    ->options(fn () => EmailTemplate::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        $template = $state ? EmailTemplate::find($state) : null;
                        if (! $template) {
                            return;
                        }
                        $c = $get('client_id') ? Client::find($get('client_id')) : null;
                        $contact = $c?->primaryContact;
                        $rendered = $template->render([
                            'klant' => $c?->name,
                            'contactpersoon' => $contact?->full_name,
                            'voornaam' => $contact?->first_name,
                            'afzender' => auth()->user()->name,
                            'bedrijf' => Filament::getTenant()?->name,
                        ]);
                        $set('subject', $rendered['subject']);
                        $set('body', $rendered['body'].((string) $get('body') !== '' ? "\n\n".$get('body') : ''));
                    }),
                TextInput::make('subject')->label(__('Onderwerp'))->required()->maxLength(255),
                Textarea::make('body')->label(__('Bericht'))->rows(12)->required(),
                Toggle::make('signature')->label(__('Handtekening toevoegen'))->default(true)
                    ->visible(fn (Get $get) => ($m = Mailbox::find($get('mailbox_id'))) && ($m->signature || $m->signature_logo_path))
                    ->helperText(__('Instellen per mailbox onder Beheer → Mailboxen.')),
                Toggle::make('include_quote')->label(__('Oorspronkelijk bericht onderaan meesturen'))->default(true)
                    ->visible(fn () => (bool) $replyTo()),
                FileUpload::make('attachments')->label(__('Bijlagen'))
                    ->multiple()
                    ->disk('local')
                    ->directory(fn () => 'companies/'.Filament::getTenant()->slug.'/mail/'.now()->format('Y/m'))
                    ->visibility('private')
                    ->storeFileNamesIn('attachment_names')
                    ->maxSize(20480)
                    ->maxFiles(10),
            ])
            ->action(function (array $data, Action $action) use ($replyTo) {
                $mailbox = Mailbox::findOrFail($data['mailbox_id']);
                $names = $data['attachment_names'] ?? [];
                $attachments = [];
                foreach ($data['attachments'] ?? [] as $path) {
                    $attachments[$names[$path] ?? basename($path)] = $path;
                }
                $linkTo = ! empty($data['client_id']) ? array_filter([Client::find($data['client_id'])]) : [];

                try {
                    app(EmailSender::class)->send(
                        $mailbox,
                        $data['to'],
                        $data['subject'],
                        $data['body'],
                        $data['cc'] ?? [],
                        $replyTo(),
                        $attachments,
                        $linkTo,
                        withSignature: (bool) ($data['signature'] ?? true),
                        quote: ($reply = $replyTo()) && ($data['include_quote'] ?? true) ? static::quote($reply) : null,
                    );
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('Versturen mislukt'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('E-mail verstuurd'))->send();
            });
    }

    protected static function quote(EmailMessage $message): string
    {
        $when = $message->sent_at?->copy()->setTimezone(FilamentTimezone::get())->format('d-m-Y H:i');
        $who = $message->from_name ?: $message->from_email;
        $lines = collect(preg_split('/\r?\n/', trim((string) $message->body_text)))->map(fn ($l) => '> '.$l)->implode("\n");

        return __('Op :date schreef :name:', ['date' => $when, 'name' => $who])."\n".$lines;
    }
}
