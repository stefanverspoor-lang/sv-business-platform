<?php

namespace App\Filament\Resources\DataJobs\Pages;

use App\Enums\DataJobStatus;
use App\Enums\DocumentCategory;
use App\Filament\Resources\DataJobs\DataJobResource;
use App\Filament\Shared\TimeEntriesRelationManager;
use App\Models\DataJob;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/** @property DataJob $record */
class ViewDataJob extends ViewRecord
{
    protected static string $resource = DataJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logProgress')
                ->label(__('Voortgang registreren'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('success')
                ->visible(fn () => auth()->user()->can('logProgress', $this->record) && $this->record->status !== DataJobStatus::Delivered)
                ->schema(TimeEntriesRelationManager::entryFields(true))
                ->modalWidth('2xl')
                ->action(function (array $data) {
                    $this->record->timeEntries()->create(TimeEntriesRelationManager::prepare($data));
                    if ($this->record->status === DataJobStatus::New) {
                        $this->record->update(['status' => DataJobStatus::InProgress]);
                    }
                    Notification::make()->success()->title(__('Voortgang opgeslagen'))->send();
                    $this->redirect(DataJobResource::getUrl('view', ['record' => $this->record]));
                }),
            Action::make('deliver')
                ->label(__('Opleveren'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('update', $this->record) && $this->record->status !== DataJobStatus::Delivered)
                ->modalDescription(fn () => $this->record->total_records && $this->record->recordsDone() < $this->record->total_records
                    ? __('Let op: er zijn :done van de :total records geregistreerd.', ['done' => $this->record->recordsDone(), 'total' => $this->record->total_records])
                    : __('De opdracht wordt als opgeleverd gemarkeerd.'))
                ->schema([
                    FileUpload::make('file')->label(__('Opleverbestand (optioneel)'))
                        ->disk('local')->visibility('private')
                        ->directory(fn () => 'companies/'.Filament::getTenant()->slug.'/documents/'.now()->format('Y/m'))
                        ->storeFileNamesIn('original_name')
                        ->maxSize(Document::MAX_SIZE_KB)
                        ->acceptedFileTypes(Document::ALLOWED_MIME_TYPES),
                    Textarea::make('delivery_notes')->label(__('Opleveringsnotitie'))->rows(3),
                ])
                ->action(function (array $data) {
                    if (! empty($data['file'])) {
                        $this->record->documents()->create([
                            'disk' => 'local',
                            'path' => $data['file'],
                            'original_name' => $data['original_name'] ?? basename($data['file']),
                            'title' => __('Oplevering').' '.$this->record->name,
                            'category' => DocumentCategory::Data,
                            'mime_type' => Storage::disk('local')->mimeType($data['file']) ?: null,
                            'size' => Storage::disk('local')->size($data['file']),
                            'uploaded_by' => auth()->id(),
                        ]);
                    }
                    $this->record->update([
                        'status' => DataJobStatus::Delivered,
                        'delivered_at' => now(),
                        'delivery_notes' => $data['delivery_notes'] ?? null,
                    ]);
                    Notification::make()->success()->title(__('Opdracht opgeleverd'))->send();
                }),
            EditAction::make()->color('gray'),
        ];
    }
}
