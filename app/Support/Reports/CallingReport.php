<?php

namespace App\Support\Reports;

use App\Enums\AgreementType;
use App\Enums\LeadStatus;
use App\Enums\OutcomeEffect;
use App\Enums\PaymentStatus;
use App\Models\CallAttempt;
use App\Models\CallOutcome;
use App\Models\CollectionFile;
use App\Models\Lead;
use App\Models\PaymentAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cijfers voor de rapportages telemarketing en pre-incasso, per opdrachtgever,
 * campagne, medewerker en periode. De periode gaat over wanneer er gebeld is
 * (gesprekken, afspraken) of wanneer dossiers zijn aangeleverd.
 */
class CallingReport
{
    public function __construct(public Carbon $from, public Carbon $until) {}

    /** Periode in de tijdzone van de gebruiker, opgeslagen als UTC. */
    public static function period(?string $from, ?string $until, string $tz): self
    {
        $start = $from ? Carbon::parse($from, $tz)->startOfDay() : now($tz)->startOfMonth();
        $end = $until ? Carbon::parse($until, $tz)->endOfDay() : now($tz)->endOfDay();

        return new self($start->utc(), $end->utc());
    }

    protected function between(Builder $query, string $column): Builder
    {
        return $query->whereBetween($column, [$this->from, $this->until]);
    }

    // ---------------------------------------------------------------- pre-incasso

    public function collection(?int $clientId): array
    {
        $files = fn () => CollectionFile::query()->when($clientId, fn ($q) => $q->where('client_id', $clientId));
        $attempts = fn () => $this->between(CallAttempt::query()->where('callable_type', 'collection_file'), 'called_at')
            ->when($clientId, fn ($q) => $q->whereIn('callable_id', $files()->select('id')));
        $agreements = fn () => $this->between(PaymentAgreement::query(), 'created_at')
            ->where('status', '!=', 'cancelled')
            ->when($clientId, fn ($q) => $q->whereIn('collection_file_id', $files()->select('id')));

        $delivered = $this->between($files(), 'created_at');
        $handled = (int) $attempts()->distinct()->count('callable_id');
        $filesWithAgreement = (int) $agreements()->distinct()->count('collection_file_id');
        $paid = $this->between($files(), 'paid_at')->where('payment_status', PaymentStatus::Paid);

        $summary = [
            __('Aangeleverde dossiers') => (int) $delivered->count(),
            __('Totaal openstaand bedrag (aangeleverd)') => $this->money((float) $this->between($files(), 'created_at')->sum('outstanding_amount')),
            __('Behandelde dossiers') => $handled,
            __('Belpogingen') => (int) $attempts()->count(),
            __('Betalingsbeloftes') => (int) $agreements()->where('type', AgreementType::Promise)->count(),
            __('Betalingsregelingen') => (int) $agreements()->where('type', AgreementType::Arrangement)->count(),
            __('Totaal afgesproken bedrag') => $this->money((float) $agreements()->where('status', 'active')->sum('total_amount')),
            __('Percentage afspraken (van behandeld)') => $handled ? number_format($filesWithAgreement / $handled * 100, 1, ',', '.').'%' : '-',
            __('Volledig betaald (gemeld)') => (int) (clone $paid)->count(),
            __('Betaald bedrag (gemeld)') => $this->money((float) (clone $paid)->sum('paid_amount')),
            __('Deels betaald (telt niet als geslaagd)') => (int) $this->between($files(), 'paid_at')->where('payment_status', PaymentStatus::Partial)->count(),
            __('Commissie') => $this->money((float) (clone $paid)->sum('commission_amount')),
        ];

        $perUser = [];
        foreach ($attempts()->selectRaw('user_id, count(*) as calls, count(distinct callable_id) as files')->groupBy('user_id')->get() as $row) {
            $userAgreements = $agreements()->where('user_id', $row->user_id);
            $perUser[] = [
                User::find($row->user_id)?->name ?? '-',
                (int) $row->calls,
                (int) $row->files,
                (int) (clone $userAgreements)->count(),
                $this->money((float) (clone $userAgreements)->where('status', 'active')->sum('total_amount')),
            ];
        }

        return [
            'summary' => $summary,
            'perUser' => ['headers' => [__('Medewerker'), __('Belpogingen'), __('Dossiers gebeld'), __('Afspraken'), __('Afgesproken bedrag')], 'rows' => $perUser],
            'perOutcome' => $this->perOutcome($attempts(), CallOutcome::MODULE_COLLECTION),
            'perMonth' => $this->perMonth($attempts(), $agreements(), 'created_at', __('Afspraken')),
        ];
    }

    // -------------------------------------------------------------- telemarketing

    public function telemarketing(?int $clientId, ?int $campaignId): array
    {
        $leads = fn () => Lead::query()
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($clientId && ! $campaignId, fn ($q) => $q->whereHas('campaign', fn ($c) => $c->where('client_id', $clientId)));
        $attempts = fn () => $this->between(CallAttempt::query()->where('callable_type', 'lead'), 'called_at')
            ->when($clientId || $campaignId, fn ($q) => $q->whereIn('callable_id', $leads()->select('id')));
        $positiveIds = CallOutcome::where('module', CallOutcome::MODULE_TELEMARKETING)->where('effect', OutcomeEffect::Positive)->pluck('id');
        $contactIds = CallOutcome::where('module', CallOutcome::MODULE_TELEMARKETING)->where('counts_as_contact', true)->pluck('id');

        $called = (int) $attempts()->distinct()->count('callable_id');
        $conversions = (int) $attempts()->whereIn('call_outcome_id', $positiveIds)->count();
        $contacts = (int) $attempts()->whereIn('call_outcome_id', $contactIds)->distinct()->count('callable_id');

        $summary = [
            __('Leads in campagne(s)') => (int) $leads()->count(),
            __('Nog te bellen') => (int) $leads()->whereIn('status', LeadStatus::open())->count(),
            __('Leads gebeld in periode') => $called,
            __('Belpogingen') => (int) $attempts()->count(),
            __('Gesproken (contact)') => $contacts,
            __('Conversies') => $conversions,
            __('Conversie (van gebeld)') => $called ? number_format($conversions / $called * 100, 1, ',', '.').'%' : '-',
            __('Conversie (van gesproken)') => $contacts ? number_format($conversions / $contacts * 100, 1, ',', '.').'%' : '-',
        ];

        $perUser = [];
        foreach ($attempts()->selectRaw('user_id, count(*) as calls, count(distinct callable_id) as leads')->groupBy('user_id')->get() as $row) {
            $conv = (int) $attempts()->where('user_id', $row->user_id)->whereIn('call_outcome_id', $positiveIds)->count();
            $perUser[] = [
                User::find($row->user_id)?->name ?? '-', (int) $row->calls, (int) $row->leads, $conv,
                $row->leads ? number_format($conv / $row->leads * 100, 1, ',', '.').'%' : '-',
            ];
        }

        return [
            'summary' => $summary,
            'perUser' => ['headers' => [__('Medewerker'), __('Belpogingen'), __('Leads gebeld'), __('Conversies'), __('Conversie')], 'rows' => $perUser],
            'perOutcome' => $this->perOutcome($attempts(), CallOutcome::MODULE_TELEMARKETING),
            'perMonth' => $this->perMonth($attempts(), $attempts()->whereIn('call_outcome_id', $positiveIds), 'called_at', __('Conversies')),
        ];
    }

    // ------------------------------------------------------------------- helpers

    protected function perOutcome(Builder $attempts, string $module): array
    {
        $counts = $attempts->selectRaw('call_outcome_id, count(*) as total')->groupBy('call_outcome_id')->pluck('total', 'call_outcome_id');
        $total = max(1, $counts->sum());
        $rows = CallOutcome::where('module', $module)->orderBy('sort')->get()
            ->filter(fn ($o) => isset($counts[$o->id]))
            ->map(fn ($o) => [$o->displayLabel(), (int) $counts[$o->id], number_format($counts[$o->id] / $total * 100, 1, ',', '.').'%'])
            ->values()->all();

        return ['headers' => [__('Resultaat'), __('Aantal'), __('Aandeel')], 'rows' => $rows];
    }

    protected function perMonth(Builder $attempts, Builder $second, string $secondColumn, string $secondLabel): array
    {
        $calls = $attempts->get(['called_at'])->groupBy(fn ($a) => $a->called_at->format('Y-m'))->map->count();
        $other = $second->get([$secondColumn])->groupBy(fn ($a) => Carbon::parse($a->{$secondColumn})->format('Y-m'))->map->count();
        $months = $calls->keys()->merge($other->keys())->unique()->sort()->values();

        return [
            'headers' => [__('Maand'), __('Belpogingen'), $secondLabel],
            'rows' => $months->map(fn ($m) => [Carbon::createFromFormat('Y-m', $m)->translatedFormat('F Y'), (int) ($calls[$m] ?? 0), (int) ($other[$m] ?? 0)])->all(),
        ];
    }

    protected function money(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }

    /** Alle onderdelen als rijen voor een Excel-export. */
    public static function toRows(array $report, string $title, string $periodLabel): array
    {
        $rows = [[$title], [$periodLabel], []];
        foreach ($report['summary'] as $label => $value) {
            $rows[] = [$label, $value];
        }
        $headings = ['perUser' => __('Per medewerker'), 'perOutcome' => __('Per gespreksresultaat'), 'perMonth' => __('Per maand')];
        foreach (array_merge($headings, $report['titles'] ?? []) as $key => $heading) {
            $rows[] = [];
            $rows[] = [$heading];
            $rows[] = $report[$key]['headers'];
            foreach ($report[$key]['rows'] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
