<?php

namespace App\Filament\Resources\Emails;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Emails\Pages\ListEmails;
use App\Filament\Resources\Emails\Pages\ViewEmail;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EmailResource extends Resource
{
    protected static ?string $model = EmailMessage::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'subject';

    protected static ?string $slug = 'emails';

    public static function getModelLabel(): string
    {
        return __('e-mail');
    }

    public static function getPluralModelLabel(): string
    {
        return __('e-mails');
    }

    public static function getNavigationLabel(): string
    {
        return __('E-mail');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('CRM');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = EmailMessage::query()->visibleTo(auth()->user())->where('direction', 'in')->where('is_spam', false)->where('is_read', false)->count();

        return $count ?: null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns(static::emailColumns())
            ->recordClasses(fn (EmailMessage $r) => $r->direction === 'in' && ! $r->is_read ? 'font-semibold' : null)
            ->filters([
                SelectFilter::make('mailbox_id')->label(__('Mailbox'))
                    ->options(fn () => Mailbox::orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('direction')->label(__('Richting'))
                    ->options(['in' => __('Ontvangen'), 'out' => __('Verzonden')]),
                TernaryFilter::make('is_read')->label(__('Gelezen')),
                TernaryFilter::make('linked')->label(__('Gekoppeld aan klant'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('links'),
                        false: fn (Builder $query) => $query->whereDoesntHave('links'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordUrl(fn (EmailMessage $r) => static::getUrl('view', ['record' => $r]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markRead')->label(__('Markeren als gelezen'))->icon(Heroicon::OutlinedEnvelopeOpen)
                        ->action(fn (Collection $records) => $records->each->update(['is_read' => true])),
                    BulkAction::make('markUnread')->label(__('Markeren als ongelezen'))->icon(Heroicon::OutlinedEnvelope)
                        ->action(fn (Collection $records) => $records->where('direction', 'in')->each->update(['is_read' => false])),
                    BulkAction::make('notSpam')->label(__('Geen spam'))->icon(Heroicon::OutlinedShieldCheck)
                        ->action(fn (Collection $records) => $records->each->update(['is_spam' => false])),
                    BulkAction::make('markSpam')->label(__('Markeren als spam'))->icon(Heroicon::OutlinedShieldExclamation)
                        ->action(fn (Collection $records) => $records->where('direction', 'in')->each->update(['is_spam' => true])),
                    DeleteEmailAction::bulk(),
                ]),
            ]);
    }

    public static function emailColumns(): array
    {
        return [
            IconColumn::make('direction')->label('')
                ->icon(fn (string $state) => $state === 'in' ? Heroicon::OutlinedArrowDownLeft : Heroicon::OutlinedArrowUpRight)
                ->color(fn (string $state) => $state === 'in' ? 'primary' : 'gray')
                ->tooltip(fn (string $state) => $state === 'in' ? __('Ontvangen') : __('Verzonden')),
            TextColumn::make('from_email')->label(__('Van / aan'))
                ->formatStateUsing(fn (EmailMessage $r) => $r->counterparty())
                ->searchable(['from_email', 'from_name']),
            TextColumn::make('subject')->label(__('Onderwerp'))->searchable()->wrap()->limit(90)
                ->icon(fn (EmailMessage $r) => $r->has_attachments ? Heroicon::OutlinedPaperClip : null)
                ->iconPosition('after'),
            TextColumn::make('assigned_to')->label(__('Toegewezen aan'))
                ->formatStateUsing(fn (EmailMessage $r) => $r->assigneeName())->toggleable(),
            TextColumn::make('sent_at')->label(__('Datum'))->dateTime('d-m-Y H:i')->sortable(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('from_email')->label(__('Van'))
                        ->formatStateUsing(fn (EmailMessage $r) => $r->from_name ? "{$r->from_name} <{$r->from_email}>" : $r->from_email),
                    TextEntry::make('to')->label(__('Aan'))
                        ->state(fn (EmailMessage $r) => collect($r->to)->pluck('email')->implode(', ')),
                    TextEntry::make('sent_at')->label(__('Datum'))->dateTime('d-m-Y H:i'),
                    TextEntry::make('cc')->label(__('Cc'))
                        ->state(fn (EmailMessage $r) => collect($r->cc)->pluck('email')->implode(', ') ?: null)
                        ->placeholder('-'),
                    TextEntry::make('mailbox.name')->label(__('Mailbox')),
                    TextEntry::make('assigned_to')->label(__('Toegewezen aan'))
                        ->state(fn (EmailMessage $r) => $r->assigneeName())->placeholder(__('Niemand')),
                    TextEntry::make('clients')->label(__('Klant'))
                        ->state(fn (EmailMessage $r) => $r->linkedClients()->visibleTo(auth()->user())->get()
                            ->map(fn (Client $c) => '<a style="text-decoration:underline" href="'.e(ClientResource::getUrl('view', ['record' => $c])).'">'.e($c->name).'</a>')
                            ->implode(', ') ?: null)
                        ->html()
                        ->placeholder(__('Niet gekoppeld'))
                        ->columnSpanFull(),
                    ViewEntry::make('attachments')->label(__('Bijlagen'))
                        ->view('filament.email-attachments')
                        ->visible(fn (EmailMessage $r) => $r->has_attachments)
                        ->columnSpanFull(),
                ]),
            Section::make()
                ->schema([
                    ViewEntry::make('body')->hiddenLabel()->view('filament.email-body'),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmails::route('/'),
            'view' => ViewEmail::route('/{record}'),
        ];
    }
}
