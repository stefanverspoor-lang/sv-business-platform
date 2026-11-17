<?php

namespace App\Filament\Resources\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\LeadStatus;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Campaigns\RelationManagers\LeadsRelationManager;
use App\Models\Campaign;
use App\Models\Client;
use App\Support\Calling\PhoneNumber;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('campagne');
    }

    public static function getPluralModelLabel(): string
    {
        return __('campagnes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Telemarketing');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Campagne'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Naam'))->required()->maxLength(255),
                    Select::make('client_id')->label(__('Opdrachtgever'))
                        ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required()
                        ->helperText(__('Opdrachtgevers zijn gewone klanten in het CRM.')),
                    Select::make('type')->label(__('Soort werk'))->options(CampaignType::class)->default(CampaignType::Appointments)->required(),
                    Select::make('status')->label(__('Status'))->options(CampaignStatus::class)->default(CampaignStatus::Active)->required()
                        ->helperText(__('Alleen actieve campagnes staan in het belscherm.')),
                    DatePicker::make('starts_on')->label(__('Startdatum')),
                    DatePicker::make('ends_on')->label(__('Einddatum')),
                    Textarea::make('description')->label(__('Omschrijving'))->rows(2)->columnSpanFull(),
                ]),
            Section::make(__('Bellen'))
                ->columns(3)
                ->schema([
                    TextInput::make('max_attempts')->label(__('Maximum aantal belpogingen'))->numeric()->minValue(1)->maxValue(50)->default(5)->required(),
                    TextInput::make('retry_after_minutes')->label(__('Opnieuw proberen na (minuten)'))->numeric()->minValue(5)->default(120)->required()
                        ->helperText(__('Bij geen gehoor of voicemail')),
                    Select::make('default_country')->label(__('Land voor nummers zonder landcode'))
                        ->options(array_combine(array_keys(PhoneNumber::COUNTRY_CODES), array_keys(PhoneNumber::COUNTRY_CODES)))
                        ->default('NL')->required(),
                    RichEditor::make('script')->label(__('Belscript'))->columnSpanFull()
                        ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'h3'])
                        ->helperText(__('Staat in het belscherm naast de gegevens van de lead.')),
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
                    TextEntry::make('client.name')->label(__('Opdrachtgever')),
                    TextEntry::make('type')->label(__('Soort werk')),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('max_attempts')->label(__('Max. belpogingen')),
                    TextEntry::make('leads_total')->label(__('Leads'))
                        ->state(fn (Campaign $r) => $r->leads()->count()),
                    TextEntry::make('leads_open')->label(__('Nog te bellen'))
                        ->state(fn (Campaign $r) => $r->leads()->whereIn('status', LeadStatus::open())->count()),
                    TextEntry::make('leads_converted')->label(__('Geconverteerd'))
                        ->state(fn (Campaign $r) => $r->leads()->where('status', LeadStatus::Converted)->count()),
                    TextEntry::make('conversion')->label(__('Conversie'))
                        ->state(fn (Campaign $r) => ($rate = $r->conversionRate()) !== null ? number_format($rate, 1, ',', '.').'%' : '-')
                        ->helperText(__('van de gebelde leads')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client')->withCount([
                'leads',
                'leads as open_leads_count' => fn (Builder $query) => $query->whereIn('status', LeadStatus::open()),
                'leads as converted_leads_count' => fn (Builder $query) => $query->where('status', LeadStatus::Converted),
            ]))
            ->columns([
                TextColumn::make('name')->label(__('Campagne'))->searchable()->sortable()
                    ->description(fn (Campaign $r) => $r->type->getLabel()),
                TextColumn::make('client.name')->label(__('Opdrachtgever'))->searchable(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('leads_count')->label(__('Leads'))->numeric(),
                TextColumn::make('open_leads_count')->label(__('Nog te bellen'))->numeric(),
                TextColumn::make('converted_leads_count')->label(__('Geconverteerd'))->numeric(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(CampaignStatus::class),
                SelectFilter::make('client_id')->label(__('Opdrachtgever'))
                    ->options(fn () => Client::query()->whereHas('campaigns')->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordUrl(fn (Campaign $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([EditAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getRelations(): array
    {
        return [LeadsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
