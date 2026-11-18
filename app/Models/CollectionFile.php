<?php

namespace App\Models;

use App\Enums\CollectionStatus;
use App\Enums\DebtorType;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToCompanyDatabase;
use App\Models\Concerns\IsCallable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pre-incassodossier: één openstaande vordering van een debiteur, aangeleverd door de opdrachtgever. */
class CollectionFile extends Model
{
    use BelongsToCompanyDatabase, IsCallable, SoftDeletes;

    protected $fillable = ['client_id', 'file_number', 'debtor_type', 'debtor_name', 'contact_name', 'phone', 'phone_alt',
        'email', 'address_line', 'postal_code', 'city', 'country', 'timezone', 'invoice_number', 'invoice_date', 'due_date',
        'original_amount', 'outstanding_amount', 'currency', 'extra', 'notes', 'status', 'assigned_to', 'attempts',
        'max_attempts', 'last_called_at', 'next_call_at', 'last_outcome_id', 'closed_at', 'data_import_id'];

    protected function casts(): array
    {
        return [
            'status' => CollectionStatus::class, 'debtor_type' => DebtorType::class, 'payment_status' => PaymentStatus::class,
            'extra' => 'array', 'invoice_date' => 'date', 'due_date' => 'date', 'paid_at' => 'date',
            'original_amount' => 'decimal:2', 'outstanding_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2', 'last_called_at' => 'datetime', 'next_call_at' => 'datetime',
            'closed_at' => 'datetime', 'commission_calculated_at' => 'datetime', 'locked_until' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(PaymentAgreement::class)->latest();
    }

    public function activeAgreement(): HasOne
    {
        return $this->hasOne(PaymentAgreement::class)->where('status', 'active')->latestOfMany();
    }

    public function paymentResults(): HasMany
    {
        return $this->hasMany(PaymentResult::class);
    }

    public function defaultCountry(): string
    {
        return $this->client_id ? ($this->loadMissing('client')->client?->country ?: 'NL') : 'NL';
    }

    public function displayName(): string
    {
        return $this->debtor_name;
    }

    public function maxAttempts(): int
    {
        return (int) ($this->max_attempts ?: 6);
    }

    public function retryAfterMinutes(): int
    {
        return 180;
    }

    public static function openStatuses(): array
    {
        return CollectionStatus::open();
    }

    /** Het bedrag dat volledig betaald moet zijn om als geslaagd te tellen. */
    public function targetAmount(): float
    {
        return (float) ($this->activeAgreement()->value('total_amount') ?? $this->outstanding_amount);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('collection.view_all') ? $query : $query->where('assigned_to', $user->getKey());
    }
}
