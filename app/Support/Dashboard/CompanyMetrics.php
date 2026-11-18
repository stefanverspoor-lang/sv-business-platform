<?php

namespace App\Support\Dashboard;

use App\Enums\CollectionStatus;
use App\Enums\DataJobStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\OutcomeEffect;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Models\CallAttempt;
use App\Models\CallOutcome;
use App\Models\Client;
use App\Models\CollectionFile;
use App\Models\DataJob;
use App\Models\EmailMessage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentAgreement;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Billing\BillingSettings;
use Illuminate\Support\Carbon;

/**
 * Kerncijfers van het actieve bedrijf voor het dashboard en het
 * totaaloverzicht. Bedragen in de basisvaluta van het bedrijf (excl. btw).
 */
class CompanyMetrics
{
    public function __construct(protected User $user) {}

    public function revenue(Carbon $from, Carbon $until): float
    {
        return round(Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereBetween('issue_date', [$from->toDateString(), $until->toDateString().' 23:59:59'])
            ->get(['subtotal', 'exchange_rate'])->sum(fn ($i) => (float) $i->subtotal * (float) $i->exchange_rate), 2);
    }

    public function expenses(Carbon $from, Carbon $until): float
    {
        return round(Expense::query()->whereBetween('date', [$from->toDateString(), $until->toDateString().' 23:59:59'])
            ->get(['amount_excl', 'exchange_rate'])->sum(fn ($e) => (float) $e->amount_excl * (float) $e->exchange_rate), 2);
    }

    /** @return array{count: int, amount: float} */
    public function openInvoices(bool $overdueOnly = false): array
    {
        $invoices = Invoice::unpaid()->when($overdueOnly, fn ($q) => $q->whereDate('due_date', '<', today()))
            ->get(['total', 'paid_total', 'exchange_rate']);

        return [
            'count' => $invoices->count(),
            'amount' => round($invoices->sum(fn ($i) => ((float) $i->total - (float) $i->paid_total) * (float) $i->exchange_rate), 2),
        ];
    }

    /** Alle cijfers in één keer (voor het totaaloverzicht). */
    public function all(): array
    {
        $monthStart = now()->startOfMonth();
        $yearStart = now()->startOfYear();
        $today = now();
        $open = $this->openInvoices();
        $overdue = $this->openInvoices(true);
        $positive = CallOutcome::where('module', CallOutcome::MODULE_TELEMARKETING)->where('effect', OutcomeEffect::Positive)->pluck('id');

        return [
            'currency' => BillingSettings::baseCurrency(),
            'revenue_month' => $this->revenue($monthStart, $today),
            'revenue_year' => $this->revenue($yearStart, $today),
            'expenses_month' => $this->expenses($monthStart, $today),
            'expenses_year' => $this->expenses($yearStart, $today),
            'open_invoices' => $open,
            'overdue_invoices' => $overdue,
            'active_clients' => Client::query()->active()->count(),
            'new_clients' => Client::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'running_projects' => Project::query()->whereIn('status', ProjectStatus::running())->count(),
            'running_data_jobs' => DataJob::query()->whereIn('status', DataJobStatus::running())->count(),
            'tm_calls_month' => CallAttempt::where('callable_type', 'lead')->where('called_at', '>=', $monthStart)->count(),
            'tm_conversions_month' => CallAttempt::where('callable_type', 'lead')->where('called_at', '>=', $monthStart)->whereIn('call_outcome_id', $positive)->count(),
            'tm_leads_open' => Lead::query()->whereIn('status', LeadStatus::open())->count(),
            'pc_calls_month' => CallAttempt::where('callable_type', 'collection_file')->where('called_at', '>=', $monthStart)->count(),
            'pc_agreements_month' => PaymentAgreement::where('created_at', '>=', $monthStart)->count(),
            'pc_paid_month' => CollectionFile::where('payment_status', PaymentStatus::Paid)->whereDate('paid_at', '>=', $monthStart)->count(),
            'pc_commission_month' => round((float) CollectionFile::where('payment_status', PaymentStatus::Paid)->whereDate('paid_at', '>=', $monthStart)->sum('commission_amount'), 2),
            'pc_files_open' => CollectionFile::query()->whereIn('status', CollectionStatus::open())->count(),
            'tasks_open' => Task::query()->open()->count(),
            'tasks_overdue' => Task::query()->overdue()->count(),
            'emails_unread' => EmailMessage::where('direction', 'in')->where('is_read', false)->count(),
        ];
    }

    /** Persoonlijke cijfers: wat de ingelogde gebruiker mag zien. */
    public function personal(): array
    {
        return [
            'tasks_open' => Task::query()->visibleTo($this->user)->open()->count(),
            'tasks_overdue' => Task::query()->visibleTo($this->user)->overdue()->count(),
            'tasks_mine_today' => Task::query()->open()->where('assigned_to', $this->user->getKey())->where('due_at', '<=', now()->endOfDay())->count(),
            'emails_unread' => EmailMessage::query()->visibleTo($this->user)->where('direction', 'in')->where('is_read', false)->count(),
            'calls_ready' => $this->user->can('calls.make')
                ? app(\App\Support\Calling\CallQueue::class)->count($this->user, 'leads') + app(\App\Support\Calling\CallQueue::class)->count($this->user, 'collection')
                : 0,
        ];
    }
}
