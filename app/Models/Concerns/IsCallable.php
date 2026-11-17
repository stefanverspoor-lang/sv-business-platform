<?php

namespace App\Models\Concerns;

use App\Models\CallAttempt;
use App\Models\CallOutcome;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\User;
use App\Support\Calling\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Gedeeld door leads (telemarketing) en dossiers (pre-incasso): belhistorie,
 * wachtrij, vergrendeling tijdens het bellen en telefoonnummer-opschoning.
 */
trait IsCallable
{
    public const LOCK_MINUTES = 15;

    public static function bootIsCallable(): void
    {
        static::saving(function ($record) {
            if ($record->isDirty(['phone', 'country']) || $record->phone_normalized === null) {
                $record->phone_normalized = PhoneNumber::normalize($record->phone, $record->country ?: $record->defaultCountry());
            }
        });
    }

    abstract public function defaultCountry(): string;

    abstract public function displayName(): string;

    abstract public function maxAttempts(): int;

    abstract public function retryAfterMinutes(): int;

    /** @return array<int, \BackedEnum> */
    abstract public static function openStatuses(): array;

    public function callAttempts(): MorphMany
    {
        return $this->morphMany(CallAttempt::class, 'callable')->latest('called_at');
    }

    public function lastOutcome(): BelongsTo
    {
        return $this->belongsTo(CallOutcome::class, 'last_outcome_id');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function emails(): MorphToMany
    {
        return $this->morphToMany(EmailMessage::class, 'linkable', 'email_links')->withPivot('is_automatic');
    }

    public function assigneeName(): ?string
    {
        return $this->assigned_to ? User::find($this->assigned_to)?->name : null;
    }

    public function isLockedFor(User $user): bool
    {
        return $this->locked_by && $this->locked_by !== $user->getKey() && $this->locked_until?->isFuture();
    }

    public function lockFor(User $user): void
    {
        $this->forceFill(['locked_by' => $user->getKey(), 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->saveQuietly();
    }

    public function unlock(): void
    {
        $this->forceFill(['locked_by' => null, 'locked_until' => null])->saveQuietly();
    }

    /** Huidige tijd bij de lead/debiteur, als de tijdzone bekend is. */
    public function localTime(): ?\Illuminate\Support\Carbon
    {
        $tz = $this->timezone ?: match (strtoupper((string) ($this->country ?: $this->defaultCountry()))) {
            'NL' => 'Europe/Amsterdam', 'BE' => 'Europe/Brussels', 'DE' => 'Europe/Berlin', 'FR' => 'Europe/Paris',
            'GB' => 'Europe/London', 'ID' => 'Asia/Makassar', 'AU' => 'Australia/Sydney', default => null,
        };

        return $tz ? now($tz) : null;
    }

    /** Alleen wat open staat, niet vergrendeld door een ander, en waarvan de belmoment is aangebroken. */
    public function scopeReadyToCall(Builder $query, User $user): Builder
    {
        return $query
            ->whereIn('status', array_map(fn ($s) => $s->value, static::openStatuses()))
            ->whereNotNull('phone_normalized')
            ->where(fn ($q) => $q->whereNull('next_call_at')->orWhere('next_call_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('locked_by')->orWhere('locked_by', $user->getKey())->orWhere('locked_until', '<', now()));
    }

    /** Volgorde in de wachtrij: eerst afgesproken terugbelmomenten, dan nooit gebeld, dan het langst geleden. */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when status = 'callback' then 0 when attempts = 0 then 1 else 2 end")
            ->orderByRaw('coalesce(next_call_at, created_at)')
            ->orderBy('id');
    }
}
