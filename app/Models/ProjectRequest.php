<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Wijzigingsverzoek of supportvraag bij een websiteproject. */
class ProjectRequest extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['type', 'title', 'description', 'status', 'requested_by', 'requested_at', 'assigned_to',
        'estimated_hours', 'billable', 'price', 'completed_at', 'resolution', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => RequestType::class, 'status' => RequestStatus::class, 'requested_at' => 'datetime',
            'completed_at' => 'datetime', 'estimated_hours' => 'decimal:2', 'billable' => 'boolean', 'price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProjectRequest $request) {
            if ($request->isDirty('status')) {
                $request->completed_at = $request->status === RequestStatus::Done ? ($request->completed_at ?? now()) : null;
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timeEntries(): MorphMany
    {
        return $this->morphMany(TimeEntry::class, 'timeable');
    }
}
