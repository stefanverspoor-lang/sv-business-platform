<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CallAttempt extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['callable_type', 'callable_id', 'user_id', 'call_outcome_id', 'phone', 'notes', 'called_at', 'duration_seconds', 'callback_at'];

    protected function casts(): array
    {
        return ['called_at' => 'datetime', 'callback_at' => 'datetime'];
    }

    public function callable(): MorphTo
    {
        return $this->morphTo();
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(CallOutcome::class, 'call_outcome_id');
    }

    public function userName(): ?string
    {
        return $this->user_id ? User::find($this->user_id)?->name : null;
    }
}
