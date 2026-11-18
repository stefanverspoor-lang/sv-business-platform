<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use App\Models\Concerns\HasBillingLines;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Quote extends Model
{
    use Auditable, BelongsToCompanyDatabase, HasBillingLines;

    protected $guarded = ['id', 'number', 'client_snapshot', 'pdf_path', 'subtotal', 'vat_total', 'total'];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class, 'issue_date' => 'date', 'valid_until' => 'date', 'exchange_rate' => 'decimal:8',
            'client_snapshot' => 'array', 'subtotal' => 'decimal:2', 'vat_total' => 'decimal:2', 'total' => 'decimal:2',
            'sent_at' => 'datetime', 'decided_at' => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Concept #:id', ['id' => $this->id]);
    }

    public function bankAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
