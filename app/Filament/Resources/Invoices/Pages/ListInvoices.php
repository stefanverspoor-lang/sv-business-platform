<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Nieuwe factuur'))];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Openstaand'))->modifyQueryUsing(fn (Builder $query) => $query->unpaid())
                ->badge(fn () => Invoice::unpaid()->count() ?: null),
            'overdue' => Tab::make(__('Te laat'))->modifyQueryUsing(fn (Builder $query) => $query->unpaid()->whereDate('due_date', '<', today()))
                ->badge(fn () => Invoice::unpaid()->whereDate('due_date', '<', today())->count() ?: null)->badgeColor('danger'),
            'draft' => Tab::make(__('Concepten'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Draft))
                ->badge(fn () => Invoice::where('status', InvoiceStatus::Draft)->count() ?: null)->badgeColor('gray'),
            'paid' => Tab::make(__('Betaald'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Paid)),
            'all' => Tab::make(__('Alle')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
