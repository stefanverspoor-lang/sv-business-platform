<?php

namespace App\Filament\Resources\Clients;

use App\Enums\ClientStatus;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ContractsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\EmailsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\TasksRelationManager;
use App\Filament\Shared\DocumentsRelationManager;
use App\Models\Client;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    // Klanten staan in de eigen database van het bedrijf; geen tenant-kolom nodig.
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('klant');
    }

    public static function getPluralModelLabel(): string
    {
        return __('klanten');
    }

    public static function getNavigationLabel(): string
    {
        return __('Klanten');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('CRM');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone', 'city'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Bedrijf'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Bedrijfsnaam'))->required()->maxLength(255),
                    Select::make('status')->label(__('Status'))
                        ->options(ClientStatus::class)->default(ClientStatus::Active)->required(),
                    TextInput::make('legal_name')->label(__('Statutaire naam'))->maxLength(255),
                    TextInput::make('registration_number')->label(__('Inschrijfnummer (bijv. KvK)'))->maxLength(50),
                    TextInput::make('vat_number')->label(__('Btw-nummer'))->maxLength(50),
                    TextInput::make('website')->label(__('Website'))->url()->maxLength(255),
                ]),
            Section::make(__('Contactgegevens'))
                ->columns(2)
                ->schema([
                    TextInput::make('email')->label(__('E-mailadres'))->email()->maxLength(255),
                    TextInput::make('phone')->label(__('Telefoonnummer'))->tel()->maxLength(40)
                        ->helperText(__('Bij voorkeur internationaal, bijv. +31 20 123 4567')),
                    TextInput::make('address_line')->label(__('Adres'))->maxLength(255),
                    TextInput::make('postal_code')->label(__('Postcode'))->maxLength(20),
                    TextInput::make('city')->label(__('Plaats'))->maxLength(100),
                    TextInput::make('country')->label(__('Landcode'))->maxLength(2)
                        ->helperText(__('Twee letters, bijv. NL, BE, ID')),
                ]),
            Section::make(__('Instellingen'))
                ->columns(2)
                ->schema([
                    Select::make('timezone')->label(__('Tijdzone van de klant'))
                        ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->searchable()
                        ->helperText(__('Leeg = jouw eigen tijdzone')),
                    Select::make('preferred_locale')->label(__('Taal voor correspondentie'))
                        ->options(config('crm.locales'))->default('nl')->required(),
                    Select::make('account_manager_id')->label(__('Accountmanager'))
                        ->options(fn () => static::companyUserOptions())
                        ->searchable(),
                    Textarea::make('notes')->label(__('Notities'))->rows(4)->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('primaryContact.full_name')->label(__('Contactpersoon'))->placeholder('-'),
                    TextEntry::make('account_manager_id')->label(__('Accountmanager'))
                        ->state(fn (Client $r) => $r->account_manager_id ? User::find($r->account_manager_id)?->name : null)
                        ->placeholder('-'),
                    TextEntry::make('phone')->label(__('Telefoon'))->placeholder('-')
                        ->url(fn (Client $r) => $r->phone ? 'tel:'.preg_replace('/[^0-9+]/', '', $r->phone) : null)
                        ->icon(Heroicon::OutlinedPhone)->color('primary'),
                    TextEntry::make('email')->label(__('E-mail'))->placeholder('-')
                        ->url(fn (Client $r) => $r->email ? 'mailto:'.$r->email : null)->icon(Heroicon::OutlinedEnvelope),
                    TextEntry::make('website')->label(__('Website'))->placeholder('-')
                        ->url(fn (Client $r) => $r->website, shouldOpenInNewTab: true),
                    TextEntry::make('address')->label(__('Adres'))
                        ->state(fn (Client $r) => collect([$r->address_line, trim($r->postal_code.' '.$r->city), $r->country])->filter()->implode(', ') ?: null)
                        ->placeholder('-'),
                    TextEntry::make('registration_number')->label(__('Inschrijfnummer'))->placeholder('-'),
                    TextEntry::make('vat_number')->label(__('Btw-nummer'))->placeholder('-'),
                    TextEntry::make('localtime')->label(__('Lokale tijd klant'))
                        ->state(fn (Client $r) => $r->timezone ? now($r->timezone)->format('H:i').' ('.$r->timezone.')' : null)
                        ->placeholder('-'),
                    TextEntry::make('notes')->label(__('Notities'))->placeholder('-')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Bedrijfsnaam'))->searchable()->sortable(),
                TextColumn::make('primaryContact.first_name')->label(__('Contactpersoon'))
                    ->formatStateUsing(fn ($record) => $record->primaryContact?->full_name),
                TextColumn::make('phone')->label(__('Telefoon'))->searchable(),
                TextColumn::make('email')->label(__('E-mail'))->searchable()->toggleable(),
                TextColumn::make('city')->label(__('Plaats'))->searchable()->sortable()->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()->sortable(),
                TextColumn::make('updated_at')->label(__('Gewijzigd'))->dateTime('d-m-Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ClientStatus::class),
                TrashedFilter::make(),
            ])
            ->recordUrl(fn (Client $record) => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
            ContactsRelationManager::class,
            TasksRelationManager::class,
            EmailsRelationManager::class,
            ContractsRelationManager::class,
            ServicesRelationManager::class,
            \App\Filament\Resources\Clients\RelationManagers\InvoicesRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->visibleTo(auth()->user());
    }

    /** @return array<int, string> */
    public static function companyUserOptions(): array
    {
        $company = Filament::getTenant();

        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('is_superadmin', true)
                ->orWhereHas('companies', fn ($c) => $c->whereKey($company?->getKey())))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
