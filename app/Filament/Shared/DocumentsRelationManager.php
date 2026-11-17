<?php

namespace App\Filament\Shared;

use App\Enums\ActivityType;
use App\Enums\DocumentCategory;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Documenten bij een klant, contactpersoon of contract.
 * Bestanden staan per bedrijf in storage/app/private/companies/{bedrijf}/documents.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documenten');
    }

    public static function getModelLabel(): string
    {
        return __('document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documenten');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            FileUpload::make('path')
                ->label(__('Bestand'))
                ->disk('local')
                ->directory(fn () => 'companies/'.Filament::getTenant()->slug.'/documents/'.now()->format('Y/m'))
                ->visibility('private')
                ->acceptedFileTypes(Document::ALLOWED_MIME_TYPES)
                ->maxSize(Document::MAX_SIZE_KB)
                ->storeFileNamesIn('original_name')
                ->required()
                ->hiddenOn('edit')
                ->helperText(__('PDF, Word, Excel, CSV of afbeelding, maximaal 20 MB.'))
                ->columnSpanFull(),
            TextInput::make('title')->label(__('Titel'))->maxLength(255)
                ->helperText(__('Leeg = bestandsnaam')),
            Select::make('category')->label(__('Soort'))->options(DocumentCategory::class)
                ->default(DocumentCategory::Other)->required(),
            Textarea::make('notes')->label(__('Notities'))->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')->label(__('Titel'))->searchable()->wrap(),
                TextColumn::make('category')->label(__('Soort'))->badge(),
                TextColumn::make('size')->label(__('Grootte'))->formatStateUsing(fn (Document $record) => $record->humanSize()),
                TextColumn::make('created_at')->label(__('Toegevoegd'))->dateTime('d-m-Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('Soort'))->options(DocumentCategory::class),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $disk = Storage::disk('local');
                        $data['disk'] = 'local';
                        $data['original_name'] ??= basename($data['path']);
                        $data['title'] = filled($data['title'] ?? null) ? $data['title'] : pathinfo($data['original_name'], PATHINFO_FILENAME);
                        $data['mime_type'] = $disk->mimeType($data['path']) ?: null;
                        $data['size'] = $disk->size($data['path']);
                        $data['uploaded_by'] = auth()->id();

                        return $data;
                    })
                    ->after(function (Document $record) {
                        $owner = $this->getOwnerRecord();
                        // Alleen bij klantgegevens in de tijdlijn; documenten van debiteuren horen niet bij de opdrachtgever.
                        $client = match (true) {
                            $owner instanceof Client => $owner,
                            $owner instanceof \App\Models\Contact, $owner instanceof \App\Models\Contract => $owner->client,
                            default => null,
                        };
                        if (! $client) {
                            return;
                        }
                        Activity::record($client, ActivityType::Document, __('Document toegevoegd: :title', ['title' => $record->title]), subject: $record);
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('Downloaden'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize(fn (Document $record) => auth()->user()->can('view', $record))
                    ->action(fn (Document $record) => Storage::disk($record->disk)->download($record->path, $record->original_name)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
