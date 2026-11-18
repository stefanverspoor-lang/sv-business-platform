<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\CallScreen;
use App\Filament\Resources\Emails\EmailResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Support\Dashboard\CompanyMetrics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Wat er vandaag voor jou klaarstaat. */
class PersonalOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '120s';

    public function getHeading(): ?string
    {
        return __('Voor jou');
    }

    protected function getStats(): array
    {
        $m = (new CompanyMetrics(auth()->user()))->personal();
        $user = auth()->user();

        return array_values(array_filter([
            Stat::make(__('Taken vandaag en te laat'), $m['tasks_mine_today'])
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color($m['tasks_mine_today'] ? 'warning' : 'success')
                ->url(TaskResource::getUrl('index', ['activeTab' => 'today'])),
            Stat::make(__('Achterstallige taken'), $m['tasks_overdue'])
                ->description($user->can('tasks.view_all') ? __('van iedereen') : __('van jou'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($m['tasks_overdue'] ? 'danger' : 'success')
                ->url(TaskResource::getUrl('index', ['activeTab' => $user->can('tasks.view_all') ? 'all' : 'mine'])),
            Stat::make(__('Ongelezen e-mails'), $m['emails_unread'])
                ->icon(Heroicon::OutlinedEnvelope)
                ->url(EmailResource::getUrl()),
            $user->can('calls.make') ? Stat::make(__('Klaar om te bellen'), $m['calls_ready'])
                ->description(__('leads en dossiers in jouw wachtrij'))
                ->icon(Heroicon::OutlinedPhone)
                ->url(CallScreen::getUrl()) : null,
        ]));
    }
}
