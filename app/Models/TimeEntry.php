<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Urenregel: tijd op een project, verzoek of data-entry-opdracht (met productie). */
class TimeEntry extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['timeable_type', 'timeable_id', 'user_id', 'date', 'minutes', 'description', 'billable',
        'records_done', 'errors_found', 'corrections'];

    protected function casts(): array
    {
        return ['date' => 'date', 'billable' => 'boolean'];
    }

    public function timeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function userName(): ?string
    {
        return User::find($this->user_id)?->name;
    }

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }

    public function subjectLabel(): string
    {
        $t = $this->timeable;

        return match (true) {
            $t instanceof Project => __('Project').': '.$t->name,
            $t instanceof ProjectRequest => __('Verzoek').': '.$t->title,
            $t instanceof DataJob => __('Data-entry').': '.$t->name,
            default => '-',
        };
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('time.view_all') ? $query : $query->where('user_id', $user->getKey());
    }

    /** "1:30", "1,5", "90m" of "2u" → minuten. */
    public static function parseDuration(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (int) round($value * 60);
        }
        $v = strtolower(trim(str_replace(',', '.', $value)));
        if (preg_match('/^(\d+):(\d{1,2})$/', $v, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d+)\s*m(in)?$/', $v, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^(\d+(\.\d+)?)\s*(u|h|uur)?$/', $v, $m)) {
            return (int) round((float) $m[1] * 60);
        }

        return null;
    }
}
