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
                ->visible(fn () => auth()->user()->can('calls.make') && ! $this->record->anonymized_at)
                ->url(fn () => CallScreen::getUrl(['type' => 'leads', 'record' => $this->record->id])),
            CallingActions::callNow(fn () => $this->record),
            CallingActions::callViaCloudTalk(fn () => $this->record),
            EditAction::make()->color('gray'),
            Action::make('anonymize')
                ->label(__('Anonimiseren (AVG)'))
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('danger')
                ->visible(fn () => ! $this->record->anonymized_at && auth()->user()->can('campaigns.manage'))
                ->requiresConfirmation()
                ->modalHeading(__('Persoonsgegevens wissen?'))
                ->modalDescription(__('Naam, telefoonnummers, e-mail, adres, notities en gespreksnotities worden definitief gewist. Status, bedragen en belpogingen blijven bewaard voor de rapportages. Dit kan niet ongedaan worden gemaakt.'))
                ->modalSubmitActionLabel(__('Definitief wissen'))
                ->action(function () {
                    app(\App\Support\Privacy\Anonymizer::class)->lead($this->record);
                    $this->record->refresh();
                    \Filament\Notifications\Notification::make()->success()->title(__('Persoonsgegevens gewist'))->send();
                }),
        ];
    }
}
