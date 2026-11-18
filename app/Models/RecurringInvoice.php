<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use App\Models\Concerns\HasBillingLines;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sjabloon waarmee periodiek een factuur wordt gemaakt (bijv. onderhoud per maand). */
class RecurringInvoice extends Model
{
    use Auditable, BelongsToCompanyDatabase, HasBillingLines;

    protected $guarded = ['id', 'last_generated_at'];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class, 'next_date' => 'date', 'end_date' => 'date',
            'auto_finalize' => 'boolean', 'auto_send' => 'boolean', 'is_active' => 'boolean', 'last_generated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Totalen bestaan niet op het sjabloon; alleen op facturen en offertes. */
    public function recalculateTotals(): void {}

    public function bankAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
