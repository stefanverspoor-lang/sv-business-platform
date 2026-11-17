<?php

namespace App\Filament\Resources\Leads;

use App\Enums\LeadStatus;
use App\Filament\Pages\CallScreen;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Shared\Calling\CallHistoryRelationManager;
use App\Filament\Shared\Calling\CallingActions;
use App\Models\Campaign;
use App\Models\Lead;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'company_name';

    public static function getModelLabel(): string
    {
        return __('lead');
    }

    public static function getPluralModelLabel(): string
    {
        return __('leads');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Telemarketing');
    }

    public static function form(Schema $schema): Schema
    {
        $canManage = fn () => auth()->user()->can('campaigns.manage');

        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('campaign_id')->label(__('Campagne'))
                        ->options(fn () => Campaign::query()->orderBy('name')->pluck('name', 'id'))
                        ->required()->disabled(fn () => ! $canManage())->dehydrated(),
                    Select::make('status')->label(__('Status'))->options(LeadStatus::class)->required()
                        ->disabled(fn () => ! $canManage())->dehydrated()
                        ->helperText(__('Normaal zet het belscherm de status. Hier alleen voor correcties.')),
                    TextInput::make('company_name')->label(__('Bedrijfsnaam'))->maxLength(255),
                    TextInput::make('contact_name')->label(__('Contactpersoon'))->maxLength(255),
                    TextInput::make('phone')->label(__('Telefoon'))->tel()->required()->maxLength(40),
                    TextInput::make('phone_alt')->label(__('Telefoon 2'))->tel()->maxLength(40),
                    TextInput::make('email')->label(__('E-mail'))->email()->maxLength(255),
                    TextInput::make('website')->label(__('Website'))->maxLength(255),
                    TextInput::make('address_line')->label(__('Adres'))->maxLength(255),
                    TextInput::make('postal_code')->label(__('Postcode'))->maxLength(20),
                    TextInput::make('city')->label(__('Plaats'))->maxLength(100),
                    TextInput::make('country')->label(__('Landcode'))->maxLength(2),
                    Select::make('assigned_to')->label(__('Toegewezen aan'))
                        ->options(fn () => ClientResource::companyUserOptions())->searchable()
                        ->disabled(fn () => ! $canManage())->dehydrated(),
                    Textarea::make('notes')->label(__('Notities'))->rows(3)->columnSpanFull(),
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
                    TextEntry::make('campaign.name')->label(__('Campagne'))
                        ->url(fn (Lead $r) => CampaignResource::getUrl('view', ['record' => $r->campaign_id])),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('assigned_to')->label(__('Toegewezen aan'))
                        ->state(fn (Lead $r) => $r->assigneeName())->placeholder(__('Niemand')),
                    TextEntry::make('contact_name')->label(__('Contactpersoon'))->placeholder('-'),
                    TextEntry::make('phone_normalized')->label(__('Telefoon'))->placeholder(__('Ongeldig nummer'))
                        ->url(fn (Lead $r) => $r->phone_normalized ? 'tel:'.$r->phone_normalized : null)->color('primary')
                        ->icon(Heroicon::OutlinedPhone),
                    TextEntry::make('email')->label(__('E-mail'))->placeholder('-'),
                    TextEntry::make('address')->label(__('Adres'))
                        ->state(fn (Lead $r) => collect([$r->address_line, trim($r->postal_code.' '.$r->city), $r->country])->filter()->implode(', ') ?: null)
                        ->placeholder('-'),
                    TextEntry::make('attempts')->label(__('Belpogingen'))
                        ->state(fn (Lead $r) => $r->attempts.' / '.$r->maxAttempts()),
                    TextEntry::make('next_call_at')->label(__('Volgende poging'))->dateTime('d-m-Y H:i')->placeholder('-'),
                    TextEntry::make('notes')->label(__('Notities'))->placeholder('-')->columnSpanFull(),
                    KeyValueEntry::make('extra')->label(__('Extra informatie uit de import'))
                        ->keyLabel(__('Kolom'))->valueLabel(__('Waarde'))
                        ->visible(fn (Lead $r) => ! empty($r->extra))->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::configureLeadTable($table, withCampaign: true);
    }

    public static function configureLeadTable(Table $table, bool $withCampaign): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lastOutcome', 'campaign']))
            ->columns(array_values(array_filter([
                TextColumn::make('company_name')->label(__('Lead'))->searchable(['company_name', 'contact_name', 'phone', 'email'])
                    ->formatStateUsing(fn (Lead $r) => $r->displayName())
                    ->description(fn (Lead $r) => $r->company_name ? $r->contact_name : null),
                $withCampaign ? TextColumn::make('campaign.name')->label(__('Campagne'))->toggleable() : null,
                TextColumn::make('phone_normalized')->label(__('Telefoon'))->placeholder(__('Ongeldig')),
                TextColumn::make('city')->label(__('Plaats'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('attempts')->label(__('Pogingen'))->numeric()->sortable(),
                TextColumn::make('lastOutcome.label')->label(__('Laatste resultaat'))
                    ->formatStateUsing(fn (Lead $r) => $r->lastOutcome?->displayLabel())->placeholder('-'),
                TextColumn::make('last_called_at')->label(__('Laatst gebeld'))->dateTime('d-m-Y H:i')->sortable()->placeholder('-'),
                TextColumn::make('assigned_to')->label(__('Toegewezen aan'))
                    ->formatStateUsing(fn (Lead $r) => $r->assigneeName())->placeholder(__('Niemand')),
            ])))
            ->filters(array_values(array_filter([
                SelectFilter::make('status')->label(__('Status'))->options(LeadStatus::class)->multiple(),
                $withCampaign ? SelectFilter::make('campaign_id')->label(__('Campagne'))
                    ->options(fn () => Campaign::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')) : null,
                SelectFilter::make('assigned_to')->label(__('Toegewezen aan'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->visible(fn () => auth()->user()->can('leads.view_all')),
            ])))
            ->recordUrl(fn (Lead $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([
                CallingActions::callNow()->iconButton()->tooltip(__('BEL NU')),
                EditAction::make()->iconButton()->url(fn (Lead $r) => static::getUrl('edit', ['record' => $r])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    CallingActions::assignBulk('campaigns.manage'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getRelations(): array
    {
        return [CallHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
