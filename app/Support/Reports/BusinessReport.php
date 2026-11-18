<?php

namespace App\Support\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\OutcomeEffect;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Enums\TaskStatus;
use App\Models\CallAttempt;
use App\Models\CallOutcome;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\CollectionFile;
use App\Models\DataJob;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentAgreement;
use App\Models\Project;
use App\Models\ProjectRequest;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Billing\BillingSettings;
use App\Support\Billing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rapportages per klant, per dienst, per medewerker en per bedrijf.
 * Alle aantallen worden met gegroepeerde queries berekend, zodat het ook
 * met tienduizenden leads en dossiers snel blijft.
 *
 * $from en $until zijn in de tijdzone van de gebruiker; tijdstippen worden
 * voor de database naar UTC omgezet.
 */
class BusinessReport
{
    protected string $currency;

    public function __construct(public Carbon $from, public Carbon $until)
    {
        $this->currency = BillingSettings::baseCurrency();
    }

    protected function dates(): array
    {
        // Tot en met het einde van de dag: werkt voor DATE-kolommen in MariaDB én in SQLite.
        return [$this->from->toDateString(), $this->until->toDateString().' 23:59:59'];
    }

    protected function moments(): array
    {
        return [$this->from->copy()->utc(), $this->until->copy()->utc()];
    }

    protected function money(float $v): string
    {
        return Money::format($v, $this->currency);
    }

    protected function hours(int|float|null $minutes): string
    {
        return number_format(((int) $minutes) / 60, 1, ',', '.');
    }

    protected function int(int|float|null $v): string
    {
        return number_format((int) $v, 0, ',', '.');
    }

    // ------------------------------------------------------------ bouwstenen

    /** Gefactureerde omzet (excl. btw, basisvaluta) per klant in de periode. */
    public function revenueByClient(): Collection
    {
        return Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereBetween('issue_date', $this->dates())
            ->toBase()->selectRaw('client_id, sum(subtotal * exchange_rate) as amount, count(*) as n')
            ->groupBy('client_id')->get()->keyBy('client_id');
    }

    /** Ontvangen betalingen per klant in de periode. */
    public function paidByClient(): Collection
    {
        return Invoice::query()->join('invoice_payments', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->whereBetween('invoice_payments.paid_at', $this->dates())
            ->toBase()->selectRaw('invoices.client_id, sum(invoice_payments.amount * invoices.exchange_rate) as amount')
            ->groupBy('invoices.client_id')->pluck('amount', 'client_id');
    }

    /** Nog openstaand per klant (nu). */
    public function openByClient(): Collection
    {
        return Invoice::unpaid()->toBase()
            ->selectRaw('client_id, sum((total - paid_total) * exchange_rate) as amount')
            ->groupBy('client_id')->pluck('amount', 'client_id');
    }

    /**
     * Minuten per (klant, dienst, medewerker) in de periode. Uren hangen aan
     * een websiteproject, een wijzigingsverzoek of een data-entry-opdracht.
     *
     * @return Collection<int, object{client_id: ?int, service: string, user_id: int, minutes: int, billable: int, records: int}>
     */
    public function timeRows(): Collection
    {
        if ($this->timeRowsCache) {
            return $this->timeRowsCache;
        }
        $rows = TimeEntry::query()->whereBetween('date', $this->dates())->toBase()
            ->selectRaw('timeable_type, timeable_id, user_id, sum(minutes) as minutes, sum(case when billable then minutes else 0 end) as billable, coalesce(sum(records_done),0) as records')
            ->groupBy('timeable_type', 'timeable_id', 'user_id')->get();

        $ids = fn ($type) => $rows->where('timeable_type', $type)->pluck('timeable_id')->unique()->all();
        $requests = ProjectRequest::query()->whereIn('id', $ids('project_request'))->pluck('project_id', 'id');
        $projects = Project::withTrashed()->whereIn('id', array_unique(array_merge($ids('project'), $requests->values()->all())))->pluck('client_id', 'id');
        $jobs = DataJob::withTrashed()->whereIn('id', $ids('data_job'))->pluck('client_id', 'id');

        return $this->timeRowsCache = $rows->map(function ($r) use ($projects, $requests, $jobs) {
            [$client, $service] = match ($r->timeable_type) {
                'project' => [$projects[$r->timeable_id] ?? null, ServiceType::Website->value],
                'project_request' => [$projects[$requests[$r->timeable_id] ?? 0] ?? null, ServiceType::Website->value],
                'data_job' => [$jobs[$r->timeable_id] ?? null, ServiceType::DataEntry->value],
                default => [null, ServiceType::Other->value],
            };

            return (object) ['client_id' => $client, 'service' => $service, 'user_id' => (int) $r->user_id,
                'minutes' => (int) $r->minutes, 'billable' => (int) $r->billable, 'records' => (int) $r->records];
        });
    }

    /**
     * Belpogingen in de periode per (klant, module, medewerker), met conversies
     * (telemarketing) en afspraken (pre-incasso).
     */
    protected ?Collection $callRowsCache = null;

    protected ?Collection $timeRowsCache = null;

    public function callRows(): Collection
    {
        if ($this->callRowsCache) {
            return $this->callRowsCache;
        }
        $positive = CallOutcome::query()->where('effect', OutcomeEffect::Positive)->pluck('id')->all();
        $positiveSql = 'sum(case when call_attempts.call_outcome_id in ('.($positive ? implode(',', array_map('intval', $positive)) : '0').') then 1 else 0 end) as positive';

        // Gegroepeerd in de database per klant en medewerker: klein resultaat, ook bij honderdduizenden pogingen.
        $leads = CallAttempt::query()->where('call_attempts.callable_type', 'lead')->whereBetween('call_attempts.called_at', $this->moments())
            ->join('leads', 'leads.id', '=', 'call_attempts.callable_id')
            ->join('campaigns', 'campaigns.id', '=', 'leads.campaign_id')
            ->toBase()->selectRaw('campaigns.client_id, call_attempts.user_id, count(*) as calls, '.$positiveSql)
            ->groupBy('campaigns.client_id', 'call_attempts.user_id')->get();
        $files = CallAttempt::query()->where('call_attempts.callable_type', 'collection_file')->whereBetween('call_attempts.called_at', $this->moments())
            ->join('collection_files', 'collection_files.id', '=', 'call_attempts.callable_id')
            ->toBase()->selectRaw('collection_files.client_id, call_attempts.user_id, count(*) as calls, '.$positiveSql)
            ->groupBy('collection_files.client_id', 'call_attempts.user_id')->get();

        $map = fn ($service) => fn ($r) => (object) [
            'client_id' => $r->client_id ? (int) $r->client_id : null,
            'service' => $service,
            'user_id' => (int) $r->user_id,
            'calls' => (int) $r->calls,
            'positive' => (int) $r->positive,
        ];

        return $this->callRowsCache = $leads->map($map(ServiceType::Telemarketing->value))
            ->concat($files->map($map(ServiceType::PreCollection->value)))->values();
    }

    /** Betalingsafspraken in de periode: [client_id, user_id, n]. */
    public function agreementRows(): Collection
    {
        return PaymentAgreement::query()->where('payment_agreements.status', '!=', 'cancelled')
            ->whereBetween('payment_agreements.created_at', $this->moments())
            ->join('collection_files', 'collection_files.id', '=', 'payment_agreements.collection_file_id')
            ->leftJoin('call_attempts', 'call_attempts.id', '=', 'payment_agreements.call_attempt_id')
            ->toBase()->selectRaw('collection_files.client_id, call_attempts.user_id, count(*) as n, sum(payment_agreements.total_amount) as amount')
            ->groupBy('collection_files.client_id', 'call_attempts.user_id')->get();
    }

    /** Volledig betaalde dossiers en commissie in de periode per klant. */
    public function commissionByClient(): Collection
    {
        return CollectionFile::query()->where('payment_status', PaymentStatus::Paid)->whereBetween('paid_at', $this->dates())
            ->toBase()->selectRaw('client_id, count(*) as n, sum(commission_amount) as amount')
            ->groupBy('client_id')->get()->keyBy('client_id');
    }

    /**
     * Omzet per dienst: gefactureerde uren/records/commissie worden via de
     * bron van de factuurregel aan een dienst gekoppeld; de rest valt onder
     * "Overig / niet gekoppeld".
     */
    public function revenueByService(): array
    {
        $out = array_fill_keys(array_map(fn ($c) => $c->value, ServiceType::cases()), 0.0);
        $invoices = Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->whereBetween('issue_date', $this->dates())->pluck('exchange_rate', 'id');

        foreach ($invoices->keys()->chunk(500) as $chunk) {
            $lines = \App\Models\BillingLine::query()->where('billable_type', 'invoice')->whereIn('billable_id', $chunk->all())
                ->get(['billable_id', 'line_total', 'source']);
            $entryIds = $lines->flatMap(fn ($l) => $l->source['time_entries'] ?? [])->unique()->all();
            $types = $entryIds ? TimeEntry::whereIn('id', $entryIds)->pluck('timeable_type', 'id') : collect();
            foreach ($lines as $line) {
                $amount = (float) $line->line_total * (float) $invoices[$line->billable_id];
                $source = $line->source ?? [];
                $service = match (true) {
                    ! empty($source['collection_files']) => ServiceType::PreCollection->value,
                    ! empty($source['time_entries']) => ($types[$source['time_entries'][0]] ?? null) === 'data_job' ? ServiceType::DataEntry->value : ServiceType::Website->value,
                    default => ServiceType::Other->value,
                };
                $out[$service] += $amount;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------- rapporten

    /** Per klant: omzet, betaald, open, uren, belpogingen, afspraken. */
    public function clients(?int $clientId = null, bool $withMoney = true): array
    {
        $revenue = $this->revenueByClient();
        $paid = $this->paidByClient();
        $open = $this->openByClient();
        $time = $this->timeRows()->groupBy('client_id');
        $calls = $this->callRows()->groupBy('client_id');
        $agreements = $this->agreementRows()->groupBy('client_id');
        $commission = $this->commissionByClient();

        $ids = collect([$revenue->keys(), $paid->keys(), $open->keys(), $time->keys(), $calls->keys(), $agreements->keys(), $commission->keys()])
            ->flatten()->filter()->unique()->when($clientId, fn ($c) => $c->intersect([$clientId]));
        $clients = Client::withTrashed()->whereIn('id', $ids->all())->orderBy('name')->get(['id', 'name']);

        $headers = array_values(array_filter([__('Klant'),
            $withMoney ? __('Gefactureerd') : null, $withMoney ? __('Ontvangen') : null, $withMoney ? __('Nu open') : null,
            __('Uren'), __('Belpogingen'), __('Afspraken'), $withMoney ? __('Commissie') : null]));
        $rows = [];
        $tot = ['rev' => 0, 'paid' => 0, 'open' => 0, 'min' => 0, 'calls' => 0, 'agr' => 0, 'com' => 0];
        foreach ($clients as $c) {
            $r = [
                'rev' => (float) ($revenue[$c->id]->amount ?? 0), 'paid' => (float) ($paid[$c->id] ?? 0), 'open' => (float) ($open[$c->id] ?? 0),
                'min' => (int) ($time[$c->id] ?? collect())->sum('minutes'), 'calls' => (int) ($calls[$c->id] ?? collect())->sum('calls'),
                'agr' => (int) ($agreements[$c->id] ?? collect())->sum('n'), 'com' => (float) ($commission[$c->id]->amount ?? 0),
            ];
            foreach ($r as $k => $v) {
                $tot[$k] += $v;
            }
            $rows[] = array_values(array_filter([$c->name,
                $withMoney ? $this->money($r['rev']) : null, $withMoney ? $this->money($r['paid']) : null, $withMoney ? $this->money($r['open']) : null,
                $this->hours($r['min']), $this->int($r['calls']), $this->int($r['agr']), $withMoney ? $this->money($r['com']) : null], fn ($v) => $v !== null));
        }

        $summary = array_filter([
            __('Klanten met activiteit') => $this->int(count($rows)),
            __('Gefactureerd') => $withMoney ? $this->money($tot['rev']) : null,
            __('Ontvangen') => $withMoney ? $this->money($tot['paid']) : null,
            __('Nu open') => $withMoney ? $this->money($tot['open']) : null,
            __('Uren') => $this->hours($tot['min']),
            __('Belpogingen') => $this->int($tot['calls']),
            __('Betalingsafspraken') => $this->int($tot['agr']),
            __('Nieuwe klanten') => $clientId ? null : $this->int(Client::query()->whereBetween('created_at', $this->moments())->count()),
        ], fn ($v) => $v !== null);

        return ['summary' => $summary, 'tables' => [
            ['title' => __('Per klant'), 'headers' => $headers, 'rows' => $rows],
        ]];
    }

    /** Per dienst: klanten, omzet, uren, belpogingen. */
    public function services(?int $clientId = null, bool $withMoney = true): array
    {
        $active = ClientService::query()->where('is_active', true)->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->toBase()->selectRaw('service, count(distinct client_id) as clients, count(*) as n')->groupBy('service')->get()->keyBy('service');
        $monthly = ClientService::query()->where('is_active', true)->where('pricing_model', 'monthly')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->toBase()->selectRaw('service, sum(price) as amount')->groupBy('service')->pluck('amount', 'service');
        $time = $this->timeRows()->when($clientId, fn ($c) => $c->where('client_id', $clientId))->groupBy('service');
        $calls = $this->callRows()->when($clientId, fn ($c) => $c->where('client_id', $clientId))->groupBy('service');
        $revenue = $clientId ? null : $this->revenueByService();

        $rows = [];
        foreach (ServiceType::cases() as $s) {
            $k = $s->value;
            $rows[] = array_values(array_filter([
                $s->getLabel(),
                $this->int($active[$k]->clients ?? 0),
                $withMoney ? $this->money((float) ($monthly[$k] ?? 0)) : null,
                $withMoney && $revenue ? $this->money($revenue[$k]) : null,
                $this->hours(($time[$k] ?? collect())->sum('minutes')),
                $this->int(($time[$k] ?? collect())->sum('records')),
                $this->int(($calls[$k] ?? collect())->sum('calls')),
                $this->int(($calls[$k] ?? collect())->sum('positive')),
            ], fn ($v) => $v !== null));
        }
        $headers = array_values(array_filter([__('Dienst'), __('Actieve klanten'), $withMoney ? __('Vaste maandprijs') : null,
            $withMoney && $revenue ? __('Gefactureerd') : null, __('Uren'), __('Records'), __('Belpogingen'), __('Positief resultaat')]));

        $campaigns = Campaign::query()->when($clientId, fn ($q) => $q->where('client_id', $clientId))->with('client:id,name')->get(['id', 'name', 'client_id']);
        $leadCampaign = Lead::withTrashed()->whereIn('campaign_id', $campaigns->pluck('id'))->toBase()
            ->selectRaw('campaign_id, count(*) as n')->groupBy('campaign_id')->pluck('n', 'campaign_id');
        $campaignRows = [];
        if ($campaigns->isNotEmpty()) {
            $attempts = CallAttempt::query()->where('callable_type', 'lead')->whereBetween('called_at', $this->moments())
                ->join('leads', 'leads.id', '=', 'call_attempts.callable_id')->whereIn('leads.campaign_id', $campaigns->pluck('id'))
                ->toBase()->selectRaw('leads.campaign_id, count(*) as calls, count(distinct leads.id) as reached')
                ->groupBy('leads.campaign_id')->get()->keyBy('campaign_id');
            $positive = CallOutcome::query()->where('effect', OutcomeEffect::Positive)->pluck('id');
            $conv = CallAttempt::query()->where('callable_type', 'lead')->whereBetween('called_at', $this->moments())->whereIn('call_outcome_id', $positive)
                ->join('leads', 'leads.id', '=', 'call_attempts.callable_id')->toBase()
                ->selectRaw('leads.campaign_id, count(*) as n')->groupBy('leads.campaign_id')->pluck('n', 'campaign_id');
            foreach ($campaigns as $c) {
                $n = (int) ($attempts[$c->id]->calls ?? 0);
                $campaignRows[] = [$c->name, $c->client?->name ?? '-', $this->int($leadCampaign[$c->id] ?? 0), $this->int($n),
                    $this->int($attempts[$c->id]->reached ?? 0), $this->int($conv[$c->id] ?? 0),
                    $n ? number_format(($conv[$c->id] ?? 0) / $n * 100, 1, ',', '.').'%' : '-'];
            }
        }

        $tables = [['title' => __('Per dienst'), 'headers' => $headers, 'rows' => $rows]];
        if ($campaignRows) {
            $tables[] = ['title' => __('Per campagne'), 'headers' => [__('Campagne'), __('Opdrachtgever'), __('Leads'), __('Belpogingen'), __('Leads gebeld'), __('Conversies'), __('Conversie per poging')], 'rows' => $campaignRows];
        }

        return ['summary' => array_filter([
            __('Actieve diensten') => $this->int($active->sum('n')),
            __('Vaste maandomzet') => $withMoney ? $this->money((float) $monthly->sum()) : null,
            __('Uren') => $this->hours($time->flatten()->sum('minutes')),
            __('Belpogingen') => $this->int($calls->flatten()->sum('calls')),
        ], fn ($v) => $v !== null), 'tables' => $tables];
    }

    /** Per medewerker: uren, belpogingen, conversies, afspraken, taken. */
    public function employees(?int $clientId = null): array
    {
        $time = $this->timeRows()->when($clientId, fn ($c) => $c->where('client_id', $clientId))->groupBy('user_id');
        $calls = $this->callRows()->when($clientId, fn ($c) => $c->where('client_id', $clientId))->groupBy('user_id');
        $agreements = $this->agreementRows()->when($clientId, fn ($c) => $c->where('client_id', $clientId))->groupBy('user_id');
        $tasks = Task::query()->where('status', TaskStatus::Done)->whereBetween('completed_at', $this->moments())
            ->when($clientId, fn ($q) => $q->where('taskable_type', 'client')->where('taskable_id', $clientId))
            ->toBase()->selectRaw('assigned_to, count(*) as n')->groupBy('assigned_to')->pluck('n', 'assigned_to');

        $ids = collect([$time->keys(), $calls->keys(), $agreements->keys(), $tasks->keys()])->flatten()->filter()->unique();
        $users = User::query()->whereIn('id', $ids->all())->orderBy('name')->pluck('name', 'id');

        $rows = [];
        foreach ($users as $id => $name) {
            $t = $time[$id] ?? collect();
            $c = $calls[$id] ?? collect();
            $rows[] = [$name,
                $this->hours($t->sum('minutes')), $this->hours($t->sum('billable')),
                $this->int($c->where('service', ServiceType::Telemarketing->value)->sum('calls')),
                $this->int($c->where('service', ServiceType::Telemarketing->value)->sum('positive')),
                $this->int($c->where('service', ServiceType::PreCollection->value)->sum('calls')),
                $this->int(($agreements[$id] ?? collect())->sum('n')),
                $this->int($t->sum('records')),
                $this->int($tasks[$id] ?? 0),
            ];
        }

        return ['summary' => [
            __('Medewerkers met activiteit') => $this->int(count($rows)),
            __('Uren') => $this->hours($time->flatten()->sum('minutes')),
            __('Belpogingen') => $this->int($calls->flatten()->sum('calls')),
            __('Taken afgerond') => $this->int($tasks->sum()),
        ], 'tables' => [[
            'title' => __('Per medewerker'),
            'headers' => [__('Medewerker'), __('Uren'), __('Declarabel'), __('Telemarketing: pogingen'), __('Conversies'),
                __('Pre-incasso: pogingen'), __('Afspraken'), __('Records'), __('Taken afgerond')],
            'rows' => $rows,
        ]]];
    }

    /** Kerncijfers van het actieve bedrijf in de periode (voor het bedrijvenrapport). */
    public function companyTotals(): array
    {
        $revenue = (float) $this->revenueByClient()->sum('amount');
        $expenses = (float) Expense::query()->whereBetween('date', $this->dates())->toBase()->selectRaw('coalesce(sum(amount_excl * exchange_rate),0) as a')->value('a');
        $time = $this->timeRows();
        $calls = $this->callRows();

        return [
            'currency' => $this->currency,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'paid' => (float) $this->paidByClient()->sum(),
            'new_clients' => Client::query()->whereBetween('created_at', $this->moments())->count(),
            'minutes' => (int) $time->sum('minutes'),
            'tm_calls' => (int) $calls->where('service', ServiceType::Telemarketing->value)->sum('calls'),
            'tm_positive' => (int) $calls->where('service', ServiceType::Telemarketing->value)->sum('positive'),
            'pc_calls' => (int) $calls->where('service', ServiceType::PreCollection->value)->sum('calls'),
            'pc_agreements' => (int) $this->agreementRows()->sum('n'),
            'commission' => (float) $this->commissionByClient()->sum('amount'),
        ];
    }

    /** @param  array<string, array|null>  $perCompany  naam => companyTotals() */
    public static function companies(array $perCompany): array
    {
        $ok = array_filter($perCompany);
        $currencies = array_unique(array_column($ok, 'currency'));
        $same = count($currencies) === 1;
        $m = fn ($v, $cur) => Money::format($v, $cur);
        $n = fn ($v) => number_format((int) $v, 0, ',', '.');

        $rows = [];
        foreach ($perCompany as $name => $t) {
            if (! $t) {
                $rows[] = [$name, __('niet beschikbaar'), '', '', '', '', '', '', '', ''];

                continue;
            }
            $rows[] = [$name, $m($t['revenue'], $t['currency']), $m($t['expenses'], $t['currency']), $m($t['revenue'] - $t['expenses'], $t['currency']),
                $m($t['paid'], $t['currency']), $n($t['new_clients']), number_format($t['minutes'] / 60, 1, ',', '.'),
                $n($t['tm_calls']).' / '.$n($t['tm_positive']), $n($t['pc_calls']).' / '.$n($t['pc_agreements']), $m($t['commission'], $t['currency'])];
        }
        if (count($ok) > 1) {
            $s = fn ($k) => array_sum(array_column($ok, $k));
            $cur = $currencies[0] ?? 'EUR';
            $rows[] = [__('Totaal'), $same ? $m($s('revenue'), $cur) : '-', $same ? $m($s('expenses'), $cur) : '-', $same ? $m($s('revenue') - $s('expenses'), $cur) : '-',
                $same ? $m($s('paid'), $cur) : '-', $n($s('new_clients')), number_format($s('minutes') / 60, 1, ',', '.'),
                $n($s('tm_calls')).' / '.$n($s('tm_positive')), $n($s('pc_calls')).' / '.$n($s('pc_agreements')), $same ? $m($s('commission'), $cur) : '-'];
        }

        $sum = fn ($k) => array_sum(array_column($ok, $k));

        return [
            'summary' => array_filter([
                __('Bedrijven') => (string) count($perCompany),
                __('Omzet') => $same ? Money::format($sum('revenue'), $currencies[0] ?? 'EUR') : null,
                __('Kosten') => $same ? Money::format($sum('expenses'), $currencies[0] ?? 'EUR') : null,
                __('Resultaat') => $same ? Money::format($sum('revenue') - $sum('expenses'), $currencies[0] ?? 'EUR') : null,
                __('Uren') => number_format($sum('minutes') / 60, 1, ',', '.'),
                __('Belpogingen') => number_format($sum('tm_calls') + $sum('pc_calls'), 0, ',', '.'),
            ], fn ($v) => $v !== null),
            'tables' => [[
                'title' => __('Per bedrijf (bedragen excl. btw)'),
                'headers' => [__('Bedrijf'), __('Omzet'), __('Kosten'), __('Resultaat'), __('Ontvangen'), __('Nieuwe klanten'), __('Uren'),
                    __('Telemarketing: pogingen / conversies'), __('Pre-incasso: pogingen / afspraken'), __('Commissie')],
                'rows' => $rows,
            ]],
        ];
    }
}
