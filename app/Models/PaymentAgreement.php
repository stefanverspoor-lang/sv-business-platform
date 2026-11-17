<?php

namespace App\Models;

use App\Enums\AgreementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Betalingsafspraak met een debiteur. Het CRM legt alleen vast wat is
 * afgesproken; het markeert nooit zelf iets als betaald.
 */
class PaymentAgreement extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    protected $fillable = ['collection_file_id', 'call_attempt_id', 'type', 'total_amount', 'installments', 'installment_amount',
        'interval', 'first_payment_date', 'notes', 'status', 'user_id'];

    protected function casts(): array
    {
        return ['type' => AgreementType::class, 'total_amount' => 'decimal:2', 'installment_amount' => 'decimal:2', 'first_payment_date' => 'date'];
    }

    public function collectionFile(): BelongsTo
    {
        return $this->belongsTo(CollectionFile::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AgreementTerm::class)->orderBy('sequence');
    }

    /** Termijnen opnieuw opbouwen; de laatste termijn vangt afrondingsverschillen op. */
    public function buildTerms(): void
    {
        $this->terms()->delete();
        $count = max(1, (int) $this->installments);
        $amount = round((float) $this->installment_amount, 2);
        $first = Carbon::parse($this->first_payment_date);

        for ($i = 1; $i <= $count; $i++) {
            $isLast = $i === $count;
            // Steeds vanaf de eerste datum rekenen: 31 jan → 28 feb → 31 mrt
            $date = match ($this->interval) {
                'weekly' => $first->copy()->addWeeks($i - 1),
                'biweekly' => $first->copy()->addWeeks(($i - 1) * 2),
                default => $first->copy()->addMonthsNoOverflow($i - 1),
            };
            $this->terms()->create([
                'sequence' => $i,
                'due_date' => $date,
                'amount' => $isLast ? round((float) $this->total_amount - $amount * ($count - 1), 2) : $amount,
            ]);
        }
    }

    public static function intervals(): array
    {
        return ['monthly' => __('Per maand'), 'biweekly' => __('Per twee weken'), 'weekly' => __('Per week')];
    }
}
