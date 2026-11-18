<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['amount', 'paid_at', 'method', 'reference', 'notes', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'date', 'method' => PaymentMethod::class];
    }

    protected static function booted(): void
    {
        static::saved(fn (InvoicePayment $p) => $p->invoice->refreshPaymentStatus());
        static::deleted(fn (InvoicePayment $p) => $p->invoice->refreshPaymentStatus());
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
