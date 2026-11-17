<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alleen lezen: wie heeft wat gedaan en wanneer.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 99;

    public static function getModelLabel(): string
    {
        return __('logregel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit-log');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit-log');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Tijdstip'))->dateTime('d-m-Y H:i:s')->sortable(),
                TextColumn::make('user.name')->label(__('Gebruiker'))->searchable(),
                TextColumn::make('event')->label(__('Actie'))->badge(),
                TextColumn::make('subject_type')->label(__('Onderdeel'))
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : null),
                TextColumn::make('subject_id')->label(__('ID')),
                TextColumn::make('ip_address')->label(__('IP-adres'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')->label(__('Actie'))->options([
                    'login' => 'login', 'login_failed' => 'login_failed', 'logout' => 'logout',
                    'created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted',
                    'restored' => 'restored', 'role_changed' => 'role_changed',
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        // Een bedrijfsmanager ziet alleen de log van zijn eigen bedrijf.
        if (! auth()->user()?->isSuperadmin()) {
            $query->where('company_id', Filament::getTenant()?->getKey());
        }

        return $query;
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
