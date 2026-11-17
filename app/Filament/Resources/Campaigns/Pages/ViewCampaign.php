<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Pages\CallScreen;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Shared\Calling\CallingActions;
use App\Models\Campaign;
use App\Support\Imports\LeadImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** @property Campaign $record */
class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        $canManage = auth()->user()->can('campaigns.manage');

        return [
            Action::make('startCalling')
                ->label(__('Start met bellen'))
                ->icon(Heroicon::Phone)
                ->color('success')
                ->visible(fn () => auth()->user()->can('calls.make'))
                ->url(fn () => CallScreen::getUrl(['type' => 'leads', 'scope' => $this->record->id])),
            CallingActions::import('importLeads', __('Leads importeren'), [
                Select::make('assigned_to')->label(__('Direct toewijzen aan'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->placeholder(__('Nog niet toewijzen')),
            ], fn (array $data, string $path, string $name) => (new LeadImporter($this->record, $data['assigned_to'] ?? null))
                ->run($path, $name, ['campaign_id' => $this->record->id, 'client_id' => $this->record->client_id]))
                ->visible($canManage)
                ->after(fn () => $this->redirect(CampaignResource::getUrl('view', ['record' => $this->record]))),
            ActionGroup::make([
                CallingActions::downloadTemplate(LeadImporter::class, __('voorbeeld-leads')),
                Action::make('export')
                    ->label(__('Leads exporteren'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => CallingActions::exportResponse(
                        str($this->record->name)->slug().'-leads',
                        [__('Bedrijfsnaam'), __('Contactpersoon'), __('Telefoon'), __('E-mail'), __('Plaats'), __('Status'),
                            __('Belpogingen'), __('Laatste resultaat'), __('Laatst gebeld'), __('Toegewezen aan'), __('Notities')],
                        $this->record->leads()->with('lastOutcome')->orderBy('id')->lazy()->map(fn ($l) => [
                            $l->company_name, $l->contact_name, $l->phone_normalized ?: $l->phone, $l->email, $l->city,
                            $l->status, $l->attempts, $l->lastOutcome?->displayLabel(),
                            $l->last_called_at?->timezone(\Filament\Support\Facades\FilamentTimezone::get())->format('d-m-Y H:i'),
                            $l->assigneeName(), $l->notes,
                        ]),
                    )),
                EditAction::make(),
            ])->visible($canManage),
        ];
    }
}
