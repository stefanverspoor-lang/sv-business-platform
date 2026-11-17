<?php

namespace App\Filament\Shared\Calling;

use App\Models\CallAttempt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Belhistorie van een lead of dossier. Alleen-lezen: gesprekken leg je vast in het belscherm. */
class CallHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'callAttempts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Belhistorie');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('called_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('outcome'))
            ->columns([
                TextColumn::make('called_at')->label(__('Wanneer'))->dateTime('d-m-Y H:i')->sortable(),
                TextColumn::make('outcome.label')->label(__('Resultaat'))->badge()
                    ->formatStateUsing(fn (CallAttempt $r) => $r->outcome?->displayLabel())
                    ->color(fn (CallAttempt $r) => $r->outcome?->color ?? 'gray'),
                TextColumn::make('notes')->label(__('Notitie'))->wrap()->placeholder('-'),
                TextColumn::make('callback_at')->label(__('Terugbellen'))->dateTime('d-m-Y H:i')->placeholder('-'),
                TextColumn::make('user_id')->label(__('Door'))->formatStateUsing(fn (CallAttempt $r) => $r->userName()),
                TextColumn::make('phone')->label(__('Nummer'))->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
