<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Pages\CallScreen;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Shared\Calling\CallingActions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    public function getTitle(): string
    {
        return $this->record->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openInCallScreen')
                ->label(__('Gesprek vastleggen'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('success')
                ->visible(fn () => auth()->user()->can('calls.make'))
                ->url(fn () => CallScreen::getUrl(['type' => 'leads', 'record' => $this->record->id])),
            CallingActions::callNow(fn () => $this->record),
            CallingActions::callViaCloudTalk(fn () => $this->record),
            EditAction::make()->color('gray'),
        ];
    }
}
