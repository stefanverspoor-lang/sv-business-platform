<?php

namespace App\Filament\Resources\CollectionFiles;

use App\Enums\CollectionStatus;
use App\Enums\DebtorType;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\CollectionFiles\Pages\EditCollectionFile;
use App\Filament\Resources\CollectionFiles\Pages\ListCollectionFiles;
use App\Filament\Resources\CollectionFiles\Pages\ViewCollectionFile;
use App\Filament\Resources\CollectionFiles\RelationManagers\AgreementsRelationManager;
use App\Filament\Resources\CollectionFiles\RelationManagers\PaymentResultsRelationManager;
use App\Filament\Shared\Calling\CallHistoryRelationManager;
use App\Filament\Shared\Calling\CallingActions;
use App\Filament\Shared\Currencies;
use App\Filament\Shared\DocumentsRelationManager;
use App\Models\Client;
use App\Models\CollectionFile;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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

class CollectionFileResource extends Resource
{
    protected static ?string $model = CollectionFile::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'debtor_name';

    protected static ?string $slug = 'dossiers';

    public static function getModelLabel(): string
    {
        return __('dossier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dossiers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Pre-incasso');
    }

    public static function money(?CollectionFile $file, mixed $amount): string
    {
        return $amount === null ? '-' : ($file?->currency ?? 'EUR').' '.number_format((float) $amount, 2, ',', '.');
    }

    public static function form(Schema $schema): Schema
    {
        $canManage = fn () => auth()->user()->can('collection.manage');

        return $schema->components([
            Section::make(__('Dossier'))
                ->columns(3)
                ->schema([
                    Select::make('client_id')->label(__('Opdrachtgever'))
                        ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required()->disabled(fn () => ! $canManage())->dehydrated(),
                    TextInput::make('file_number')->label(__('Dossiernummer'))->required()->maxLength(100)
                        ->disabled(fn () => ! $canManage())->dehydrated(),
                    Select::make('status')->label(__('Status'))->options(CollectionStatus::class)->default(CollectionStatus::New)->required()
                        ->disabled(fn () => ! $canManage())->dehydrated()
                        ->helperText(__('Normaal zet het belscherm de status.')),
                    TextInput::make('invoice_number')->label(__('Factuurnummer'))->maxLength(100),
                    DatePicker::make('invoice_date')->label(__('Factuurdatum')),
                    DatePicker::make('due_date')->label(__('Vervaldatum')),
                    TextInput::make('original_amount')->label(__('Oorspronkelijk bedrag'))->numeric()->minValue(0)->required()
                        ->disabled(fn () => ! $canManage())->dehydrated(),
                    TextInput::make('outstanding_amount')->label(__('Openstaand bedrag'))->numeric()->minValue(0.01)->required()
                        ->disabled(fn () => ! $canManage())->dehydrated(),
                    Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default('EUR')->required(),
                ]),
            Section::make(__('Debiteur'))
                ->columns(3)
                ->schema([
                    TextInput::make('debtor_name')->label(__('Naam debiteur'))->required()->maxLength(255),
                    Select::make('debtor_type')->label(__('Soort'))->options(DebtorType::class)->default(DebtorType::Private)->required(),
                    TextInput::make('contact_name')->label(__('Contactpersoon'))->maxLength(255),
                    TextInput::make('phone')->label(__('Telefoon'))->tel()->maxLength(40),
                    TextInput::make('phone_alt')->label(__('Telefoon 2'))->tel()->maxLength(40),
                    TextInput::make('email')->label(__('E-mail'))->email()->maxLength(255),
                    TextInput::make('address_line')->label(__('Adres'))->maxLength(255),
                    TextInput::make('postal_code')->label(__('Postcode'))->maxLength(20),
                    TextInput::make('city')->label(__('Plaats'))->maxLength(100),
                    TextInput::make('country')->label(__('Landcode'))->maxLength(2),
                    TextInput::make('max_attempts')->label(__('Maximum belpogingen'))->numeric()->minValue(1)->default(6)->required()
                        ->disabled(fn () => ! $canManage())->dehydrated(),
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
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('client.name')->label(__('Opdrachtgever')),
                    TextEntry::make('file_number')->label(__('Dossiernummer'))->copyable(),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('payment_status')->label(__('Betaling (volgens opdrachtgever)'))->badge(),
                    TextEntry::make('outstanding_amount')->label(__('Openstaand'))
                        ->state(fn (CollectionFile $r) => static::money($r, $r->outstanding_amount)),
                    TextEntry::make('original_amount')->label(__('Oorspronkelijk'))
                        ->state(fn (CollectionFile $r) => static::money($r, $r->original_amount)),
                    TextEntry::make('paid_amount')->label(__('Betaald'))
                        ->state(fn (CollectionFile $r) => static::money($r, $r->paid_amount)),
                    TextEntry::make('commission_amount')->label(__('Commissie'))
                        ->state(fn (CollectionFile $r) => static::money($r, $r->commission_amount)),
                    TextEntry::make('invoice_number')->label(__('Factuurnummer'))->placeholder('-'),
                    TextEntry::make('invoice_date')->label(__('Factuurdatum'))->date('d-m-Y')->placeholder('-'),
                    TextEntry::make('due_date')->label(__('Vervaldatum'))->date('d-m-Y')->placeholder('-'),
                    TextEntry::make('assigned_to')->label(__('Toegewezen aan'))
                        ->state(fn (CollectionFile $r) => $r->assigneeName())->placeholder(__('Niemand')),
                ]),
            Section::make(__('Debiteur'))
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('debtor_name')->label(__('Naam')),
                    TextEntry::make('debtor_type')->label(__('Soort')),
                    TextEntry::make('phone_normalized')->label(__('Telefoon'))->placeholder(__('Geen bruikbaar nummer'))
                        ->url(fn (CollectionFile $r) => $r->phone_normalized ? 'tel:'.$r->phone_normalized : null)
                        ->icon(Heroicon::OutlinedPhone)->color('primary'),
                    TextEntry::make('email')->label(__('E-mail'))->placeholder('-'),
                    TextEntry::make('contact_name')->label(__('Contactpersoon'))->placeholder('-'),
                    TextEntry::make('address')->label(__('Adres'))
                        ->state(fn (CollectionFile $r) => collect([$r->address_line, trim($r->postal_code.' '.$r->city), $r->country])->filter()->implode(', ') ?: null)
                        ->placeholder('-'),
                    TextEntry::make('attempts')->label(__('Belpogingen'))->state(fn (CollectionFile $r) => $r->attempts.' / '.$r->maxAttempts()),
                    TextEntry::make('next_call_at')->label(__('Volgende poging'))->dateTime('d-m-Y H:i')->placeholder('-'),
                    TextEntry::make('notes')->label(__('Notities'))->placeholder('-')->columnSpanFull(),
                    KeyValueEntry::make('extra')->label(__('Extra informatie uit de import'))
                        ->keyLabel(__('Kolom'))->valueLabel(__('Waarde'))
                        ->visible(fn (CollectionFile $r) => ! empty($r->extra))->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'lastOutcome']))
            ->columns([
                TextColumn::make('file_number')->label(__('Dossier'))->searchable()->sortable()
                    ->description(fn (CollectionFile $r) => $r->client?->name),
                TextColumn::make('debtor_name')->label(__('Debiteur'))->searchable(['debtor_name', 'contact_name', 'phone', 'email', 'invoice_number'])
                    ->description(fn (CollectionFile $r) => $r->phone_normalized),
                TextColumn::make('outstanding_amount')->label(__('Openstaand'))->sortable()->alignEnd()
                    ->formatStateUsing(fn (CollectionFile $r) => static::money($r, $r->outstanding_amount)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('attempts')->label(__('Pogingen'))->numeric()->sortable(),
                TextColumn::make('lastOutcome.label')->label(__('Laatste resultaat'))
                    ->formatStateUsing(fn (CollectionFile $r) => $r->lastOutcome?->displayLabel())->placeholder('-')->toggleable(),
                TextColumn::make('payment_status')->label(__('Betaling'))->badge()->toggleable(),
                TextColumn::make('next_call_at')->label(__('Volgende poging'))->dateTime('d-m-Y H:i')->sortable()->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assigned_to')->label(__('Toegewezen aan'))
                    ->formatStateUsing(fn (CollectionFile $r) => $r->assigneeName())->placeholder(__('Niemand'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('client_id')->label(__('Opdrachtgever'))
                    ->options(fn () => Client::query()->whereHas('collectionFiles')->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('status')->label(__('Status'))->options(CollectionStatus::class)->multiple(),
                SelectFilter::make('payment_status')->label(__('Betaling'))->options(PaymentStatus::class),
                SelectFilter::make('assigned_to')->label(__('Toegewezen aan'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->visible(fn () => auth()->user()->can('collection.view_all')),
            ])
            ->recordUrl(fn (CollectionFile $r) => static::getUrl('view', ['record' => $r]))
            ->recordActions([
                CallingActions::callNow()->iconButton()->tooltip(__('BEL NU')),
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    CallingActions::assignBulk('collection.manage'),
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
        return [
            CallHistoryRelationManager::class,
            AgreementsRelationManager::class,
            PaymentResultsRelationManager::class,
            \App\Filament\Resources\Clients\RelationManagers\EmailsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollectionFiles::route('/'),
            'view' => ViewCollectionFile::route('/{record}'),
            'edit' => EditCollectionFile::route('/{record}/edit'),
        ];
    }
}
