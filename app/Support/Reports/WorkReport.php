<?php

namespace App\Support\Reports;

use App\Models\DataJob;
use App\Models\Project;
use App\Models\ProjectRequest;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Productiviteit data-entry en urenoverzicht, per medewerker en periode. */
class WorkReport
{
    public function __construct(public Carbon $from, public Carbon $until) {}

    protected function entries(): Builder
    {
        return TimeEntry::query()->whereBetween('date', [$this->from->toDateString(), $this->until->toDateString()]);
    }

    protected function hours(int|float|null $minutes): string
    {
        return number_format(((int) $minutes) / 60, 1, ',', '.');
    }

    protected function rate(int|float $part, int|float $total): string
    {
        return $total > 0 ? number_format($part / $total * 100, 1, ',', '.').'%' : '-';
    }

    public function dataEntry(?int $clientId): array
    {
        $jobIds = DataJob::withTrashed()->when($clientId, fn ($q) => $q->where('client_id', $clientId))->pluck('id');
        $entries = fn () => $this->entries()->where('timeable_type', 'data_job')->whereIn('timeable_id', $jobIds);

        $totals = $entries()->selectRaw('coalesce(sum(minutes),0) m, coalesce(sum(records_done),0) r, coalesce(sum(errors_found),0) e, coalesce(sum(corrections),0) c')->first();
        $value = DataJob::whereIn('id', $entries()->distinct()->pluck('timeable_id'))->get()->sum(fn (DataJob $j) => $j->value() ?? 0);

        $summary = [
            __('Records verwerkt') => number_format((int) $totals->r, 0, ',', '.'),
            __('Uren') => $this->hours($totals->m),
            __('Records per uur') => $totals->m > 0 ? number_format($totals->r / ($totals->m / 60), 1, ',', '.') : '-',
            __('Fouten gevonden') => (int) $totals->e,
            __('Correcties') => (int) $totals->c,
            __('Foutpercentage') => $this->rate((int) $totals->e, (int) $totals->r),
            __('Opdrachten opgeleverd') => DataJob::whereIn('id', $jobIds)->whereBetween('delivered_at', [$this->from, $this->until])->count(),
            __('Waarde (lopend + opgeleverd)') => '€ '.number_format($value, 2, ',', '.'),
        ];

        $perUser = $entries()->selectRaw('user_id, sum(minutes) m, sum(records_done) r, sum(errors_found) e, sum(corrections) c')
            ->groupBy('user_id')->get()->map(fn ($row) => [
                User::find($row->user_id)?->name ?? '-', number_format((int) $row->r, 0, ',', '.'), $this->hours($row->m),
                $row->m > 0 ? number_format($row->r / ($row->m / 60), 1, ',', '.') : '-', (int) $row->e, $this->rate((int) $row->e, (int) $row->r),
            ])->all();

        $perJob = $entries()->selectRaw('timeable_id, sum(minutes) m, sum(records_done) r, sum(errors_found) e')
            ->groupBy('timeable_id')->get()->map(function ($row) {
                $job = DataJob::withTrashed()->with('client')->find($row->timeable_id);

                return [
                    $job?->name.' ('.$job?->client?->name.')', number_format((int) $row->r, 0, ',', '.').' / '.number_format((int) $job?->total_records, 0, ',', '.'),
                    $this->hours($row->m), (int) $row->e, $job?->status?->getLabel(),
                ];
            })->all();

        return [
            'summary' => $summary,
            'perUser' => ['headers' => [__('Medewerker'), __('Records'), __('Uren'), __('Records per uur'), __('Fouten'), __('Foutpercentage')], 'rows' => $perUser],
            'perOutcome' => ['headers' => [__('Opdracht'), __('Records (periode / totaal)'), __('Uren'), __('Fouten'), __('Status')], 'rows' => $perJob],
            'perMonth' => $this->perMonth($entries()),
            'titles' => ['perOutcome' => __('Per opdracht')],
        ];
    }

    public function hoursOverview(?int $clientId): array
    {
        $entries = fn () => $this->entries()->when($clientId, fn (Builder $q) => $q->where(function (Builder $q) use ($clientId) {
            $q->where(fn ($p) => $p->where('timeable_type', 'project')->whereIn('timeable_id', Project::withTrashed()->where('client_id', $clientId)->select('id')))
                ->orWhere(fn ($p) => $p->where('timeable_type', 'project_request')->whereIn('timeable_id', ProjectRequest::whereIn('project_id', Project::withTrashed()->where('client_id', $clientId)->select('id'))->select('id')))
                ->orWhere(fn ($p) => $p->where('timeable_type', 'data_job')->whereIn('timeable_id', DataJob::withTrashed()->where('client_id', $clientId)->select('id')));
        }));

        $total = (int) $entries()->sum('minutes');
        $billable = (int) $entries()->where('billable', true)->sum('minutes');
        $labels = ['project' => __('Websiteprojecten'), 'project_request' => __('Verzoeken en support'), 'data_job' => __('Data-entry')];

        $summary = [__('Totaal uren') => $this->hours($total), __('Factureerbaar') => $this->hours($billable), __('Niet factureerbaar') => $this->hours($total - $billable),
            __('Factureerbaar aandeel') => $this->rate($billable, $total)];
        foreach ($entries()->selectRaw('timeable_type, sum(minutes) m')->groupBy('timeable_type')->pluck('m', 'timeable_type') as $type => $m) {
            $summary[$labels[$type] ?? $type] = $this->hours($m);
        }

        $perUser = $entries()->selectRaw('user_id, sum(minutes) m, sum(case when billable then minutes else 0 end) b')
            ->groupBy('user_id')->get()->map(fn ($row) => [User::find($row->user_id)?->name ?? '-', $this->hours($row->m), $this->hours($row->b), $this->rate((int) $row->b, (int) $row->m)])->all();

        $perSubject = $entries()->with('timeable')->get()->groupBy(fn (TimeEntry $e) => $e->subjectLabel())
            ->map(fn ($group, $label) => [$label, $this->hours($group->sum('minutes')), $this->hours($group->where('billable', true)->sum('minutes'))])
            ->sortByDesc(fn ($row) => (float) str_replace(',', '.', $row[1]))->values()->all();

        return [
            'summary' => $summary,
            'perUser' => ['headers' => [__('Medewerker'), __('Uren'), __('Factureerbaar'), __('Aandeel')], 'rows' => $perUser],
            'perOutcome' => ['headers' => [__('Waaraan gewerkt'), __('Uren'), __('Factureerbaar')], 'rows' => $perSubject],
            'perMonth' => $this->perMonth($entries()),
            'titles' => ['perOutcome' => __('Per project of opdracht')],
        ];
    }

    protected function perMonth(Builder $entries): array
    {
        $rows = $entries->get(['date', 'minutes', 'records_done'])->groupBy(fn ($e) => $e->date->format('Y-m'))->sortKeys()
            ->map(fn ($group, $m) => [Carbon::createFromFormat('Y-m', $m)->translatedFormat('F Y'), $this->hours($group->sum('minutes')), number_format((int) $group->sum('records_done'), 0, ',', '.')])
            ->values()->all();

        return ['headers' => [__('Maand'), __('Uren'), __('Records')], 'rows' => $rows];
    }
}
