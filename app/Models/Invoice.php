<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use App\Models\Concerns\HasBillingLines;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Verkoopfactuur of creditnota. Een concept is vrij te wijzigen; bij
 * "definitief maken" krijgt het een nummer, worden klant- en bedrijfsgegevens
 * vastgelegd en de PDF bewaard. Daarna wijzigt er niets meer (alleen
 * betalingen; corrigeren gaat met een creditnota).
 */
class Invoice extends Model
{
    use Auditable, BelongsToCompanyDatabase, HasBillingLines;

    protected $guarded = ['id', 'number', 'finalized_at', 'client_snapshot', 'company_snapshot', 'pdf_path', 'paid_total', 'subtotal', 'vat_total', 'total'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class, 'status' => InvoiceStatus::class, 'issue_date' => 'date', 'due_date' => 'date',
            'exchange_rate' => 'decimal:8', 'client_snapshot' => 'array', 'company_snapshot' => 'array',
            'subtotal' => 'decimal:2', 'vat_total' => 'decimal:2', 'total' => 'decimal:2', 'paid_total' => 'decimal:2',
            'finalized_at' => 'datetime', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
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

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_at');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credited_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credited_invoice_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function emails(): MorphToMany
    {
        return $this->morphToMany(EmailMessage::class, 'linkable', 'email_links')->withPivot('is_automatic');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isCreditNote(): bool
    {
        return $this->type === InvoiceType::CreditNote;
    }

    public function outstanding(): float
    {
        return round((float) $this->total - (float) $this->paid_total, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, InvoiceStatus::outstanding(), true) && $this->due_date?->isPast() && ! $this->due_date->isToday();
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Concept #:id', ['id' => $this->id]);
    }

    /** Bedrag in de basisvaluta (excl. btw); creditnota's tellen negatief (hun regels zijn al negatief). */
    public function baseSubtotal(): float
    {
        return round((float) $this->subtotal * (float) $this->exchange_rate, 2);
    }

    /** Betaalstatus opnieuw bepalen aan de hand van de geregistreerde betalingen. */
    public function refreshPaymentStatus(): void
    {
        if ($this->isDraft() || $this->status === InvoiceStatus::Cancelled) {
            return;
        }

        $paid = round((float) $this->payments()->sum('amount'), 2);
        $total = (float) $this->total;
        $status = match (true) {
            $total == 0.0 || ($total > 0 && $paid >= $total - 0.005) || ($total < 0 && $paid <= $total + 0.005) => InvoiceStatus::Paid,
            $paid != 0.0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Open,
        };

        $this->forceFill([
            'paid_total' => $paid,
            'status' => $status,
            'paid_at' => $status === InvoiceStatus::Paid ? ($this->paid_at ?? now()) : null,
        ])->saveQuietly();
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::outstanding());
    }

    public function bankAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
