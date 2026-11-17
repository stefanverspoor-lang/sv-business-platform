<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\LeadStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    protected $fillable = ['client_id', 'name', 'type', 'status', 'max_attempts', 'retry_after_minutes', 'default_country',
        'starts_on', 'ends_on', 'description', 'script', 'created_by'];

    protected function casts(): array
    {
        return ['type' => CampaignType::class, 'status' => CampaignStatus::class, 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** Campagnes waar je toegang toe hebt: alles, of waar je leads in hebt. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('leads.view_all')) {
            return $query;
        }

        return $query->whereHas('leads', fn (Builder $q) => $q->where('assigned_to', $user->getKey()));
    }

    public function conversionRate(): ?float
    {
        $called = $this->leads()->where('attempts', '>', 0)->count();

        return $called ? round($this->leads()->where('status', LeadStatus::Converted)->count() / $called * 100, 1) : null;
    }
}
