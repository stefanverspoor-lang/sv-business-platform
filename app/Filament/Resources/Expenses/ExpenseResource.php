<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Shared\Billing\FinanceForm;
use App\Filament\Shared\DocumentsRelationManager;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Support\Billing\Money;
use BackedEnum;
use Filament\Actions\BulkAction;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Uitgaven en inkoopfacturen. */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'uitgaven';

    public static function getModelLabel(): string
    {
        return __('uitgave');
    }

    public static function getPluralModelLabel(): string
    {
        return __('uitgaven');
    }

    public static function getNavigationLabel(): string
    {
        return __('Uitgaven en inkoop');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Financiën');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull()
                ->schema([
                    DatePicker::make('date')->label(__('Datum'))->default(now())->required(),
                    TextInput::make('supplier')->label(__('Leverancier'))->required()->maxLength(255)
                        ->datalist(fn () => Expense::query()->distinct()->orderBy('supplier')->limit(200)->pluck('supplier')->all()),
                    TextInput::make('invoice_number')->label(__('Factuurnummer leverancier'))->maxLength(255)
                        ->helperText(__('Alleen bij een inkoopfactuur')),
                    Select::make('finance_category_id')->label(__('Categorie'))->options(fn () => FinanceCategory::options('expense'))->required(),
                    TextInput::make('description')->label(__('Omschrijving'))->maxLength(255)->columnSpan(2),
                    ...FinanceForm::amountFields(),
                    DatePicker::make('due_date')->label(__('Te betalen vóór')),
                    DatePicker::make('paid_at')->label(__('Betaald op'))->helperText(__('Leeg = nog te betalen')),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                TextColumn::make('date')->label(__('Datum'))->date('d-m-Y')->sortable(),
                TextColumn::make('supplier')->label(__('Leverancier'))->searchable(['supplier', 'description', 'invoice_number'])
                    ->description(fn (Expense $r) => $r->description),
                TextColumn::make('category.name')->label(__('Categorie'))->placeholder('-'),
                TextColumn::make('total')->label(__('Totaal'))->alignEnd()->sortable()
                    ->formatStateUsing(fn (Expense $r) => Money::format($r->total, $r->currency)),
                TextColumn::make('vat_amount')->label(__('Btw'))->alignEnd()->toggleable()
                    ->formatStateUsing(fn (Expense $r) => Money::format($r->vat_amount, $r->currency)),
                TextColumn::make('paid_at')->label(__('Betaald'))->date('d-m-Y')
                    ->placeholder(__('Nog te betalen'))
                    ->color(fn (Expense $r) => ! $r->paid_at && $r->due_date?->isPast() ? 'danger' : null),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([DatePicker::make('from')->label(__('Van')), DatePicker::make('until')->label(__('Tot en met'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
                SelectFilter::make('finance_category_id')->label(__('Categorie'))->options(fn () => FinanceCategory::options('expense')),
                TernaryFilter::make('paid')->label(__('Betaald'))
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('paid_at'), false: fn (Builder $q) => $q->whereNull('paid_at'), blank: fn (Builder $q) => $q),
            ])
            ->recordActions([EditAction::make()->iconButton()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markPaid')->label(__('Markeren als betaald'))->icon(Heroicon::OutlinedCheck)
                        ->schema([DatePicker::make('paid_at')->label(__('Betaald op'))->default(now())->required()])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['paid_at' => $data['paid_at']])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
