<?php

namespace App\Filament\Resources\Mailboxes;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Filament\Resources\Mailboxes\Pages\EditMailbox;
use App\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use App\Models\Mailbox;
use App\Support\Mail\MailboxConnector;
use App\Support\Mail\MailboxSync;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class MailboxResource extends Resource
{
    protected static ?string $model = Mailbox::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 80;

    public static function getModelLabel(): string
    {
        return __('mailbox');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mailboxen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function form(Schema $schema): Schema
    {
        $encryption = ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => __('Geen')];

        return $schema->components([
            Section::make(__('Mailbox'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Naam afzender'))->required()->maxLength(255)
                        ->helperText(__('Zoals ontvangers het zien, bijv. Stefan Verspoor')),
                    TextInput::make('email')->label(__('E-mailadres'))->email()->required()->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, $set, $get) => blank($get('username')) ? $set('username', $state) : null),
                    TextInput::make('username')->label(__('Gebruikersnaam'))->required()->maxLength(255)
                        ->helperText(__('Bij Hostinger is dit het volledige e-mailadres')),
                    TextInput::make('password')->label(__('Wachtwoord van de mailbox'))->password()->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText(__('Wordt versleuteld opgeslagen. Leeg laten = niet wijzigen.')),
                    Select::make('default_assignee')->label(__('Nieuwe mail standaard naar'))
                        ->options(fn () => ClientResource::companyUserOptions())->searchable()
                        ->helperText(__('Als de afzender geen bekende klant is')),
                    DatePicker::make('sync_since')->label(__('Mail ophalen vanaf'))
                        ->default(now()->subDays(30))
                        ->helperText(__('Alleen bij de eerste keer ophalen')),
                    Toggle::make('is_active')->label(__('Actief'))->default(true),
                ]),
            Section::make(__('Handtekening'))
                ->description(__('Komt onder elke e-mail vanuit deze mailbox (uit te zetten per bericht). {naam} wordt de naam van wie verstuurt, {email} het adres van de mailbox.'))
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    RichEditor::make('signature')->label(__('Tekst'))->columnSpan(['lg' => 2])
                        ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['bulletList'], ['undo', 'redo']])
                        ->placeholder(__("Met vriendelijke groet,\n{naam}\nSV Business Support\n+62 ...")),
                    FileUpload::make('signature_logo_path')->label(__('Logo (optioneel)'))
                        ->image()->disk('local')->visibility('private')
                        ->directory(fn () => 'companies/'.\Filament\Facades\Filament::getTenant()->slug.'/signatures')
                        ->maxSize(512)
                        ->helperText(__('PNG of JPG, maximaal 512 kB. Wordt meegestuurd in de e-mail.')),
                ]),
            Section::make(__('Serverinstellingen'))
                ->description(__('Standaard ingevuld voor Hostinger. Alleen aanpassen bij een andere provider.'))
                ->collapsed(fn (string $operation) => $operation === 'create')
                ->columns(3)
                ->schema([
                    TextInput::make('imap_host')->label(__('IMAP-server'))->default('imap.hostinger.com')->required(),
                    TextInput::make('imap_port')->label(__('IMAP-poort'))->numeric()->default(993)->required(),
                    Select::make('imap_encryption')->label(__('Beveiliging'))->options($encryption)->default('ssl')->required(),
                    TextInput::make('smtp_host')->label(__('SMTP-server'))->default('smtp.hostinger.com')->required(),
                    TextInput::make('smtp_port')->label(__('SMTP-poort'))->numeric()->default(465)->required(),
                    Select::make('smtp_encryption')->label(__('Beveiliging'))->options($encryption)->default('ssl')->required(),
                    TextInput::make('sent_folder')->label(__('Map voor verzonden mail'))->default('INBOX.Sent')
                        ->helperText(__('Wordt automatisch herkend')),
                    TextInput::make('spam_folder')->label(__('Spam-map'))->placeholder('INBOX.Junk')
                        ->helperText(__('Wordt automatisch herkend; spam wordt ook opgehaald.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Naam'))->description(fn (Mailbox $r) => $r->email),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
                TextColumn::make('last_synced_at')->label(__('Laatst opgehaald'))->since()->placeholder(__('Nog niet')),
                TextColumn::make('last_error')->label(__('Melding'))->color('danger')->limit(80)->placeholder('-')->wrap(),
            ])
            ->recordActions([
                static::testAction(),
                Action::make('syncNow')->label(__('Nu ophalen'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->action(function (Mailbox $record) {
                        $count = app(MailboxSync::class)->sync($record);
                        $record->refresh();
                        $record->last_error
                            ? Notification::make()->danger()->title(__('Ophalen mislukt'))->body($record->last_error)->send()
                            : Notification::make()->success()->title(trans_choice(':count nieuw bericht|:count nieuwe berichten', $count, ['count' => $count]))->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function testAction(): Action
    {
        return Action::make('test')->label(__('Verbinding testen'))->icon(Heroicon::OutlinedSignal)->color('gray')
            ->action(function (Mailbox $record) {
                try {
                    $client = app(MailboxConnector::class)->connect($record);
                    $folders = $client->getFolders(false)->count();
                    $client->disconnect();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('IMAP mislukt'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                try {
                    $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                        $record->smtp_host, $record->smtp_port, $record->smtp_encryption === 'ssl' ? true : null,
                    );
                    if ($record->smtp_encryption === 'none') {
                        $transport->setAutoTls(false);
                    }
                    $transport->setUsername($record->username);
                    $transport->setPassword($record->password);
                    $transport->start();
                    $transport->stop();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('SMTP mislukt'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('Verbinding werkt'))
                    ->body(__('Ontvangen (IMAP) en versturen (SMTP) zijn in orde.'))->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailboxes::route('/'),
            'create' => CreateMailbox::route('/create'),
            'edit' => EditMailbox::route('/{record}/edit'),
        ];
    }
}
