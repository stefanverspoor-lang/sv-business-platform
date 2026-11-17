<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\Permissions\Roles;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

/**
 * Gebruikers van het actieve bedrijf. De rol geldt voor dit bedrijf;
 * voor het andere bedrijf kies je daar een (andere) rol.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('gebruiker');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gebruikers');
    }

    public static function getNavigationLabel(): string
    {
        return __('Gebruikers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account'))->columns(2)->schema([
                TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
                TextInput::make('email')->label(__('E-mailadres'))->email()->required()
                    ->unique('central.users', 'email', ignoreRecord: true),
                TextInput::make('password')->label(__('Wachtwoord'))->password()->revealable()
                    ->rule(Password::defaults())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(__('Minimaal 12 tekens met hoofdletters, kleine letters en cijfers. Leeg laten om niet te wijzigen.')),
                Select::make('locale')->label(__('Taal'))->options(config('crm.locales'))->default('nl')->required(),
                Toggle::make('is_active')->label(__('Actief'))->default(true)->inline(false)
                    ->helperText(__('Gedeactiveerde gebruikers kunnen niet meer inloggen.')),
            ]),
            Section::make(__('Rol in dit bedrijf'))->schema([
                Select::make('company_role')->label(__('Rol'))
                    ->options(Roles::labels())
                    ->required()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Select $component, ?User $record) {
                        $component->state($record?->roles->first()?->name);
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Naam'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('E-mailadres'))->searchable(),
                TextColumn::make('roles.name')->label(__('Rol'))->badge()
                    ->formatStateUsing(fn (string $state) => Roles::label($state)),
                IconColumn::make('is_superadmin')->label(__('Superadmin'))->boolean(),
                IconColumn::make('is_active')->label(__('Actief'))->boolean(),
                TextColumn::make('last_login_at')->label(__('Laatst ingelogd'))->dateTime('d-m-Y H:i')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        $company = Filament::getTenant();

        return parent::getEloquentQuery()
            ->whereHas('companies', fn (Builder $query) => $query->whereKey($company?->getKey()))
            ->with('roles');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
