<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Models\Concerns\BelongsToCompanyDatabase;
use App\Models\Concerns\IsCallable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToCompanyDatabase, IsCallable, SoftDeletes;

    protected $fillable = ['campaign_id', 'company_name', 'contact_name', 'phone', 'phone_alt', 'email', 'address_line',
        'postal_code', 'city', 'country', 'timezone', 'website', 'extra', 'notes', 'status', 'assigned_to',
        'attempts', 'last_called_at', 'next_call_at', 'last_outcome_id', 'converted_at', 'data_import_id'];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class, 'extra' => 'array', 'last_called_at' => 'datetime',
            'next_call_at' => 'datetime', 'converted_at' => 'datetime', 'locked_until' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function defaultCountry(): string
    {
        return $this->campaignSetting('default_country') ?: 'NL';
    }

    public function displayName(): string
    {
        return $this->company_name ?: ($this->contact_name ?: (string) $this->phone);
    }

    public function maxAttempts(): int
    {
        return (int) ($this->campaignSetting('max_attempts') ?: 5);
    }

    public function retryAfterMinutes(): int
    {
        return (int) ($this->campaignSetting('retry_after_minutes') ?: 120);
    }

    protected function campaignSetting(string $key): mixed
    {
        return $this->campaign_id ? $this->loadMissing('campaign')->campaign?->{$key} : null;
    }

    public static function openStatuses(): array
    {
        return LeadStatus::open();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('leads.view_all') ? $query : $query->where('assigned_to', $user->getKey());
    }
}
