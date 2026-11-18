<?php

namespace App\Filament\Resources\Incomes;

use App\Filament\Resources\Incomes\Pages\CreateIncome;
use App\Filament\Resources\Incomes\Pages\EditIncome;
use App\Filament\Resources\Incomes\Pages\ListIncomes;
use App\Filament\Shared\Billing\FinanceForm;
use App\Filament\Shared\DocumentsRelationManager;
use App\Models\Client;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Support\Billing\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Inkomsten die niet via een factuur uit het CRM lopen (rente, teruggaaf, contant ...). */
class IncomeResource extends Resource
{
    protected static ?string $model = Income::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'inkomsten';

    public static function getModelLabel(): string
    {
        return __('inkomst');
    }

    public static function getPluralModelLabel(): string
    {
        return __('overige inkomsten');
    }

    public static function getNavigationLabel(): string
    {
        return __('Overige inkomsten');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Financiën');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description(__('Inkomsten uit facturen van het CRM tellen al automatisch mee. Registreer hier alleen wat daarbuiten binnenkomt.'))
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('date')->label(__('Datum'))->default(now())->required(),
                    TextInput::make('description')->label(__('Omschrijving'))->required()->maxLength(255),
                    Select::make('finance_category_id')->label(__('Categorie'))->options(fn () => FinanceCategory::options('income'))->required(),
                    Select::make('client_id')->label(__('Klant (optioneel)'))->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                    ...FinanceForm::amountFields(),
                    FinanceForm::paymentMethod(),
                    Textarea::make('notes')->label(__('Notities'))->rows(2)->columnSpanFull(),
                    FinanceForm::attachment()->visibleOn('create'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'client']))
            ->columns([
                TextColumn::make('date')->label(__('Datum'))->date('d-m-Y')->sortable(),
                TextColumn::make('description')->label(__('Omschrijving'))->searchable()->description(fn (Income $r) => $r->client?->name),
                TextColumn::make('category.name')->label(__('Categorie'))->placeholder('-'),
                TextColumn::make('total')->label(__('Totaal'))->alignEnd()->sortable()->formatStateUsing(fn (Income $r) => Money::format($r->total, $r->currency)),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([DatePicker::make('from')->label(__('Van')), DatePicker::make('until')->label(__('Tot en met'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->recordActions([EditAction::make()->iconButton()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncomes::route('/'),
            'create' => CreateIncome::route('/create'),
            'edit' => EditIncome::route('/{record}/edit'),
        ];
    }
}
