<?php

namespace App\Filament\Resources\Projects;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\DomainsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\HostingsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\RequestsRelationManager;
use App\Filament\Shared\Currencies;
use App\Filament\Shared\DocumentsRelationManager;
use App\Filament\Shared\TimeEntriesRelationManager;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'websiteprojecten';

    public static function getModelLabel(): string
    {
        return __('websiteproject');
    }

    public static function getPluralModelLabel(): string
    {
        return __('websiteprojecten');
    }

    public static function getNavigationLabel(): string
    {
        return __('Projecten');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Websites');
    }

    public static function money(?Project $p, mixed $amount): ?string
    {
        return $amount === null ? null : ($p?->currency ?? 'EUR').' '.number_format((float) $amount, 2, ',', '.');
    }

    public static function form(Schema $schema): Schema
    {
        $canManage = fn () => auth()->user()->can('projects.manage');

        return $schema->components([
            Section::make(__('Project'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
                    Select::make('client_id')->label(__('Klant'))
                        ->options(fn () => Client::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required()->live()->disabled(fn () => ! $canManage())->dehydrated(),
                    Select::make('status')->label(__('Status'))->options(ProjectStatus::class)->default(ProjectStatus::New)->required(),
                    Select::make('assigned_to')->label(__('Projectmedewerker'))
                        ->options(fn () => ClientResource::companyUserOptions())->searchable()
                        ->default(fn () => auth()->id())
                        ->disabled(fn () => ! $canManage())->dehydrated(),
                    TextInput::make('website_url')->label(__('Website'))->url()->maxLength(255)->placeholder('https://'),
                    Select::make('contract_id')->label(__('Contract'))
                        ->options(fn (Get $get) => $get('client_id') ? Contract::where('client_id', $get('client_id'))->pluck('title', 'id') : [])
                        ->placeholder(__('Geen')),
                    DatePicker::make('start_date')->label(__('Startdatum')),
                    DatePicker::make('deadline')->label(__('Deadline')),
                    Textarea::make('description')->label(__('Omschrijving'))->rows(3)->columnSpanFull(),
                ]),
            Section::make(__('Prijs en onderhoud'))
                ->columns(3)
                ->schema([
                    TextInput::make('price')->label(__('Prijs bouw'))->numeric()->minValue(0),
                    TextInput::make('maintenance_fee')->label(__('Onderhoud per maand'))->numeric()->minValue(0),
                    Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default('EUR')->required(),
                    DatePicker::make('maintenance_since')->label(__('Onderhoud sinds')),
                    Textarea::make('maintenance_terms')->label(__('Onderhoudsafspraken'))->rows(3)->columnSpan(2)
                        ->placeholder(__('Bijv. 2 uur per maand inbegrepen, updates, back-ups, reactietijd')),
                    Textarea::make('notes')->label(__('Notities'))->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('client.name')->label(__('Klant'))
                        ->url(fn (Project $r) => ClientResource::getUrl('view', ['record' => $r->client_id])),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('assigned_to')->label(__('Projectmedewerker'))->state(fn (Project $r) => $r->assigneeName())->placeholder('-'),
                    TextEntry::make('website_url')->label(__('Website'))->placeholder('-')->url(fn (Project $r) => $r->website_url, true),
                    TextEntry::make('start_date')->label(__('Startdatum'))->date('d-m-Y')->placeholder('-'),
                    TextEntry::make('deadline')->label(__('Deadline'))->date('d-m-Y')->placeholder('-')
                        ->color(fn (Project $r) => $r->isOverdue() ? 'danger' : null),
                    TextEntry::make('delivered_at')->label(__('Opgeleverd'))->date('d-m-Y')->placeholder('-'),
                    TextEntry::make('contract.title')->label(__('Contract'))->placeholder('-'),
                    TextEntry::make('price')->label(__('Prijs bouw'))->state(fn (Project $r) => static::money($r, $r->price))->placeholder('-'),
                    TextEntry::make('maintenance_fee')->label(__('Onderhoud per maand'))->state(fn (Project $r) => static::money($r, $r->maintenance_fee))->placeholder('-'),
                    TextEntry::make('monthly')->label(__('Doorlopende kosten per maand'))
                        ->state(fn (Project $r) => static::money($r, $r->monthlyTotal()))
                        ->helperText(__('onderhoud + hosting + domeinen')),
                    TextEntry::make('hours')->label(__('Uren besteed'))
                        ->state(fn (Project $r) => number_format($r->timeEntries()->sum('minutes') / 60, 1, ',', '.')),
                    TextEntry::make('description')->label(__('Omschrijving'))->placeholder('-')->columnSpanFull(),
                    TextEntry::make('maintenance_terms')->label(__('Onderhoudsafspraken'))->placeholder('-')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('deadline')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client')->withCount(['requests as open_requests_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress'])]))
            ->columns([
                TextColumn::make('name')->label(__('Project'))->searchable()->sortable()->description(fn (Project $r) => $r->client?->name),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('deadline')->label(__('Deadline'))->date('d-m-Y')->sortable()->placeholder('-')
                    ->color(fn (Project $r) => $r->isOverdue() ? 'danger' : null),
                TextColumn::make('open_requests_count')->label(__('Open verzoeken'))->numeric(),
                TextColumn::make('maintenance_fee')->label(__('Onderhoud p/m'))->formatStateUsing(fn (Project $r) => static::money($r, $r->maintenance_fee))->placeholder('-')->toggleable(),
                TextColumn::make('assigned_to')->label(__('Projectmedewerker'))->formatStateUsing(fn (Project $r) => $r->assigneeName())->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ProjectStatus::class)->multiple(),
                SelectFilter::make('client_id')->label(__('Klant'))
                    ->options(fn () => Client::query()->whereHas('projects')->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('assigned_to')->label(__('Projectmedewerker'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->visible(fn () => auth()->user()->can('projects.view_all')),
            ])
            ->recordUrl(fn (Project $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([EditAction::make()->iconButton()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getRelations(): array
    {
        return [
            RequestsRelationManager::class,
            TasksRelationManager::class,
            DomainsRelationManager::class,
            HostingsRelationManager::class,
            TimeEntriesRelationManager::class,
            \App\Filament\Resources\Clients\RelationManagers\EmailsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
