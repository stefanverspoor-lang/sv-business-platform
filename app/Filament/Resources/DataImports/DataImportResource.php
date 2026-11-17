<?php

namespace App\Filament\Resources\DataImports;

use App\Filament\Resources\DataImports\Pages\ListDataImports;
use App\Models\DataImport;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class DataImportResource extends Resource
{
    protected static ?string $model = DataImport::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 83;

    protected static ?string $slug = 'imports';

    public static function getModelLabel(): string
    {
        return __('import');
    }

    public static function getPluralModelLabel(): string
    {
        return __('imports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Importlogboek');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('client'))
            ->columns([
                TextColumn::make('created_at')->label(__('Datum'))->dateTime('d-m-Y H:i')->sortable(),
                TextColumn::make('type')->label(__('Soort'))->formatStateUsing(fn (DataImport $r) => $r->typeLabel())->badge(),
                TextColumn::make('client.name')->label(__('Opdrachtgever'))->placeholder('-'),
                TextColumn::make('original_name')->label(__('Bestand'))->wrap(),
                TextColumn::make('imported')->label(__('Verwerkt'))->numeric(),
                TextColumn::make('duplicates')->label(__('Dubbel'))->numeric(),
                TextColumn::make('failed')->label(__('Fouten'))->numeric()->color(fn (DataImport $r) => $r->failed ? 'danger' : null),
                TextColumn::make('user_id')->label(__('Door'))->formatStateUsing(fn (DataImport $r) => User::find($r->user_id)?->name),
            ])
            ->filters([SelectFilter::make('type')->label(__('Soort'))->options([
                DataImport::TYPE_LEADS => __('Leads'), DataImport::TYPE_COLLECTION => __('Dossiers'), DataImport::TYPE_PAYMENTS => __('Betalingen'),
            ])])
            ->recordActions([
                Action::make('messages')->label(__('Meldingen'))->icon(Heroicon::OutlinedExclamationTriangle)->color('gray')
                    ->visible(fn (DataImport $r) => ! empty($r->messages))
                    ->modalHeading(fn (DataImport $r) => $r->original_name)
                    ->modalSubmitAction(false)
                    ->schema(fn (DataImport $r) => [
                        TextEntry::make('list')->hiddenLabel()
                            ->state(new HtmlString('<ul style="margin:0;padding-left:1rem">'.collect($r->messages)->map(fn ($m) => '<li>'.e($m).'</li>')->implode('').'</ul>')),
                    ]),
                Action::make('download')->label(__('Bestand'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (DataImport $r) => Storage::disk('local')->exists($r->path))
                    ->action(fn (DataImport $r) => Storage::disk('local')->download($r->path, $r->original_name)),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->where(function ($query) use ($user) {
            $query->whereRaw('1 = 0');
            if ($user->can('campaigns.manage')) {
                $query->orWhere('type', DataImport::TYPE_LEADS);
            }
            if ($user->can('collection.manage')) {
                $query->orWhereIn('type', [DataImport::TYPE_COLLECTION, DataImport::TYPE_PAYMENTS]);
            }
        });
    }

    public static function getPages(): array
    {
        return ['index' => ListDataImports::route('/')];
    }
}
