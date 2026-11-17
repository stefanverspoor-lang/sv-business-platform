<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\Recurrence;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    protected $fillable = [
        'taskable_type', 'taskable_id', 'title', 'description', 'status', 'priority',
        'assigned_to', 'due_at', 'remind_at', 'reminded_at', 'recurrence',
        'recurring_parent_id', 'completed_at', 'created_by',
    ];

    protected $attributes = [
        'status' => 'new',
        'priority' => 'normal',
        'recurrence' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'recurrence' => Recurrence::class,
            'due_at' => 'datetime',
            'remind_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recurringParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurring_parent_id');
    }

    public function assigneeName(): ?string
    {
        return $this->assigned_to ? User::whereKey($this->assigned_to)->value('name') : null;
    }

    public function isOverdue(): bool
    {
        return $this->status !== TaskStatus::Done && $this->due_at && $this->due_at->isPast();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', TaskStatus::Done);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /** Taken die een gebruiker mag zien: eigen taken, of alles met tasks.view_all. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperadmin() || $user->can('tasks.view_all')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('assigned_to', $user->getKey())->orWhere('created_by', $user->getKey()));
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === TaskStatus::Done ? ($task->completed_at ?? now()) : null;
            }
            // Nieuwe herinneringstijd: opnieuw herinneren.
            if ($task->isDirty('remind_at')) {
                $task->reminded_at = null;
            }
        });

        static::updated(function (Task $task) {
            if (! $task->wasChanged('status') || $task->status !== TaskStatus::Done) {
                return;
            }

            if ($task->taskable instanceof Client) {
                Activity::record($task->taskable, ActivityType::Task, __('Taak afgerond: :title', ['title' => $task->title]), subject: $task);
            }

            $task->scheduleNextOccurrence();
        });
    }

    /** Maakt bij een terugkerende taak de volgende taak aan. */
    public function scheduleNextOccurrence(): ?self
    {
        if ($this->recurrence === Recurrence::None) {
            return null;
        }

        $base = $this->due_at ?? now();
        $nextDue = $this->recurrence->next($base);
        $root = $this->recurring_parent_id ?? $this->getKey();

        // Twee keer afronden (bijv. heropend en weer afgerond) maakt geen dubbele taak.
        if (static::where('recurring_parent_id', $root)->where('due_at', $nextDue)->exists()) {
            return null;
        }

        $offset = $this->remind_at && $this->due_at ? $this->due_at->diffInSeconds($this->remind_at, false) : null;

        return static::create([
            'taskable_type' => $this->taskable_type,
            'taskable_id' => $this->taskable_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'assigned_to' => $this->assigned_to,
            'due_at' => $nextDue,
            'remind_at' => $offset !== null ? $nextDue->copy()->addSeconds((int) $offset) : null,
            'recurrence' => $this->recurrence,
            'recurring_parent_id' => $root,
            'created_by' => $this->created_by,
        ]);
    }
}
