<?php

namespace App\Filament\Resources\RecurringInvoices\Pages;

use App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Models\Project;
use App\Models\VatRate;
use App\Support\Billing\BillingSettings;
use App\Support\Tenancy\TenantManager;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringInvoice extends CreateRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    public ?int $projectId = null;

    public function mount(): void
    {
        parent::mount();

        // Vanuit een websiteproject: onderhoud per maand alvast invullen
        $project = request()->integer('project') ? Project::query()->visibleTo(auth()->user())->find(request()->integer('project')) : null;
        $locale = app(TenantManager::class)->current()?->default_locale ?: 'nl';
        $this->projectId = $project?->id;

        $state = $this->form->getRawState();
        $first = array_key_first($state['lines'] ?? []);
        if ($first !== null && $project) {
            $state['lines'][$first] = array_merge($state['lines'][$first], [
                'description' => $locale === 'en' ? 'Website maintenance '.$project->name.' {period}' : 'Onderhoud website '.$project->name.' {periode}',
                'unit' => 'maand', 'unit_price' => $project->maintenance_fee,
            ]);
        }
        $this->form->fill(array_merge($state, [
            'name' => $project ? __('Onderhoud :name', ['name' => $project->name]) : null,
            'client_id' => $project?->client_id,
            'interval' => 'monthly',
            'next_date' => now()->startOfMonth()->addMonthNoOverflow()->toDateString(),
            'payment_terms_days' => (int) BillingSettings::get('payment_terms_days'),
            'locale' => $locale,
            'currency' => $project?->currency ?? BillingSettings::baseCurrency(),
            'is_active' => true,
            'subject' => $project ? ($locale === 'en' ? 'Website maintenance {period}' : 'Onderhoud website {periode}') : null,
        ]));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id(), 'project_id' => $this->projectId];
    }
}
