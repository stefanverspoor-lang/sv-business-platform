<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eén regel in de tijdlijn (contacthistorie) van een klant.
 */
class Activity extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['client_id', 'subject_type', 'subject_id', 'type', 'title', 'body', 'occurred_at', 'user_id'];

    protected function casts(): array
    {
        return ['type' => ActivityType::class, 'occurred_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function userName(): ?string
    {
        return $this->user_id ? User::whereKey($this->user_id)->value('name') : null;
    }

    public static function record(?Client $client, ActivityType $type, string $title, ?string $body = null, ?Model $subject = null, ?int $userId = null, $at = null): self
    {
        return static::create([
            'client_id' => $client?->getKey(),
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'occurred_at' => $at ?? now(),
            'user_id' => $userId ?? auth()->id(),
        ]);
    }
}
