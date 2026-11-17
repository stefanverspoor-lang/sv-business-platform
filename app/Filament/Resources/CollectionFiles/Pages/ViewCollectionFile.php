<?php

namespace App\Filament\Resources\CollectionFiles\Pages;

use App\Filament\Pages\CallScreen;
use App\Filament\Resources\CollectionFiles\CollectionFileResource;
use App\Filament\Shared\Calling\CallingActions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCollectionFile extends ViewRecord
{
    protected static string $resource = CollectionFileResource::class;

    public function getTitle(): string
    {
        return $this->record->file_number.' · '.$this->record->debtor_name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openInCallScreen')
                ->label(__('Gesprek vastleggen'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('success')
                ->visible(fn () => auth()->user()->can('calls.make'))
                ->url(fn () => CallScreen::getUrl(['type' => 'collection', 'record' => $this->record->id])),
            CallingActions::callNow(fn () => $this->record),
            CallingActions::callViaCloudTalk(fn () => $this->record),
            EditAction::make()->color('gray'),
        ];
    }
}
