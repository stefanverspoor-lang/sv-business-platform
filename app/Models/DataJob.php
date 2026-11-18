<?php

namespace App\Models;

use App\Enums\DataJobStatus;
use App\Enums\PricingModel;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data-entry-opdracht. Voortgang, fouten en uren worden per medewerker
 * geregistreerd als urenregel (time_entries). De prijs komt uit de
 * prijsafspraak (dienst) van de klant.
 */
class DataJob extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    protected $fillable = ['client_id', 'client_service_id', 'name', 'description', 'instructions', 'status',
        'total_records', 'deadline', 'delivered_at', 'delivery_notes', 'created_by'];

    protected function casts(): array
    {
        return ['status' => DataJobStatus::class, 'deadline' => 'date', 'delivered_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ClientService::class, 'client_service_id');
    }

    public function timeEntries(): MorphMany
    {
        return $this->morphMany(TimeEntry::class, 'timeable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** Medewerkers (gebruikers staan in de centrale database, de koppeling per bedrijf). */
    public function assigneeIds(): array
    {
        return DB::connection($this->getConnectionName())->table('data_job_user')
            ->where('data_job_id', $this->getKey())->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function syncAssignees(array $userIds): void
    {
        $table = DB::connection($this->getConnectionName())->table('data_job_user');
        $table->where('data_job_id', $this->getKey())->delete();
        $table->insert(collect($userIds)->filter()->unique()->map(fn ($id) => ['data_job_id' => $this->getKey(), 'user_id' => (int) $id])->values()->all());
    }

    public function assignees(): Collection
    {
        return User::whereIn('id', $this->assigneeIds())->orderBy('name')->get();
    }

    public function recordsDone(): int
    {
        return (int) $this->timeEntries()->sum('records_done');
    }

    public function progress(): ?float
    {
        return $this->total_records > 0 ? min(100, round($this->recordsDone() / $this->total_records * 100, 1)) : null;
    }

    public function hours(): float
    {
        return round($this->timeEntries()->sum('minutes') / 60, 2);
    }

    /** Waarde volgens de prijsafspraak; null als die niet automatisch te berekenen is. */
    public function value(): ?float
    {
        $service = $this->service;
        if (! $service || $service->price === null) {
            return null;
        }

        return match ($service->pricing_model) {
            PricingModel::PerRecord => round($this->recordsDone() * (float) $service->price, 2),
            PricingModel::PerHour => round($this->hours() * (float) $service->price, 2),
            PricingModel::PerProject => (float) $service->price,
            default => null, // maandbedrag of eigen afspraak: niet per opdracht
        };
    }

    public function isOverdue(): bool
    {
        return $this->deadline && $this->deadline->isPast() && in_array($this->status, DataJobStatus::running(), true);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('data_jobs.view_all')) {
            return $query;
        }

        return $query->whereIn('id', DB::connection($this->getConnectionName())->table('data_job_user')
            ->where('user_id', $user->getKey())->select('data_job_id'));
    }
}
