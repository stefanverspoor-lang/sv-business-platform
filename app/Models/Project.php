<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Websiteproject voor een klant. De website zelf staat op de hosting van de klant. */
class Project extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    protected $fillable = ['client_id', 'contract_id', 'name', 'status', 'website_url', 'description', 'assigned_to',
        'start_date', 'deadline', 'delivered_at', 'price', 'currency', 'maintenance_fee', 'maintenance_since',
        'maintenance_terms', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class, 'start_date' => 'date', 'deadline' => 'date', 'delivered_at' => 'datetime',
            'price' => 'decimal:2', 'maintenance_fee' => 'decimal:2', 'maintenance_since' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if ($project->isDirty('status') && $project->status === ProjectStatus::Delivered && ! $project->delivered_at) {
                $project->delivered_at = now();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ProjectDomain::class);
    }

    public function hostings(): HasMany
    {
        return $this->hasMany(ProjectHosting::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ProjectRequest::class)->latest();
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function emails(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(EmailMessage::class, 'linkable', 'email_links')->withPivot('is_automatic');
    }

    public function timeEntries(): MorphMany
    {
        return $this->morphMany(TimeEntry::class, 'timeable');
    }

    public function assigneeName(): ?string
    {
        return $this->assigned_to ? User::find($this->assigned_to)?->name : null;
    }

    /** Doorlopende kosten per maand: onderhoud + hosting (+ domeinen omgerekend per maand). */
    public function monthlyTotal(): float
    {
        return round((float) $this->maintenance_fee
            + (float) $this->hostings()->sum('monthly_cost')
            + (float) $this->domains()->sum('yearly_cost') / 12, 2);
    }

    public function isOverdue(): bool
    {
        return $this->deadline && $this->deadline->isPast() && in_array($this->status, ProjectStatus::running(), true);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('projects.view_all') ? $query : $query->where('assigned_to', $user->getKey());
    }
}
