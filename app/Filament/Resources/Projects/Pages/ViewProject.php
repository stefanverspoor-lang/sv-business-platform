<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** @property Project $record */
class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('status')
                ->label(__('Status wijzigen'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('update', $this->record))
                ->fillForm(fn () => ['status' => $this->record->status])
                ->schema([Select::make('status')->label(__('Status'))->options(ProjectStatus::class)->required()])
                ->action(function (array $data) {
                    $this->record->update(['status' => $data['status']]);
                    Notification::make()->success()->title(__('Status bijgewerkt'))->send();
                }),
            Action::make('visit')
                ->label(__('Website openen'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn () => filled($this->record->website_url))
                ->url(fn () => $this->record->website_url, true),
            Action::make('billMaintenance')
                ->label(__('Onderhoud factureren'))
                ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('invoices.manage') && (float) $this->record->maintenance_fee > 0
                    && ! \App\Models\RecurringInvoice::where('project_id', $this->record->id)->where('is_active', true)->exists())
                ->url(fn () => \App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource::getUrl('create', ['project' => $this->record->id])),
            EditAction::make(),
        ];
    }
}
