<?php

namespace App\Support\Calling;

use App\Enums\AgreementType;
use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Enums\OutcomeEffect;
use App\Models\CallAttempt;
use App\Models\CallOutcome;
use App\Models\CollectionFile;
use App\Models\Lead;
use App\Models\PaymentAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Legt een gesprek vast en zet de lead of het dossier in de juiste stand:
 * opnieuw proberen, terugbellen, geslaagd, betalingsafspraak of afgesloten.
 */
class CallRecorder
{
    /**
     * @param  Lead|CollectionFile  $record
     * @param  array{type: string, total_amount: float|string, installments?: int, installment_amount?: float|string, interval?: string, first_payment_date: string, notes?: ?string}|null  $agreement
     */
    public function record(
        Model $record,
        User $user,
        CallOutcome $outcome,
        ?string $notes = null,
        ?Carbon $callbackAt = null,
        ?array $agreement = null,
        ?int $durationSeconds = null,
    ): CallAttempt {
        $module = $record instanceof Lead ? CallOutcome::MODULE_TELEMARKETING : CallOutcome::MODULE_COLLECTION;
        if ($outcome->module !== $module) {
            throw new InvalidArgumentException('Dit gespreksresultaat hoort bij een andere module.');
        }
        if ($outcome->effect === OutcomeEffect::Callback && ! $callbackAt) {
            throw new InvalidArgumentException(__('Kies wanneer je moet terugbellen.'));
        }
        if ($outcome->effect === OutcomeEffect::Agreement && (! $record instanceof CollectionFile || ! $agreement)) {
            throw new InvalidArgumentException(__('Vul de betalingsafspraak in.'));
        }

        return DB::connection($record->getConnectionName())->transaction(function () use ($record, $user, $outcome, $notes, $callbackAt, $agreement, $durationSeconds) {
            $attempt = $record->callAttempts()->create([
                'user_id' => $user->getKey(),
                'call_outcome_id' => $outcome->getKey(),
                'phone' => $record->phone_normalized ?: $record->phone,
                'notes' => $notes,
                'called_at' => now(),
                'duration_seconds' => $durationSeconds,
                'callback_at' => $callbackAt,
            ]);

            $record->attempts = (int) $record->attempts + 1;
            $record->last_called_at = now();
            $record->last_outcome_id = $outcome->getKey();
            $record->locked_by = null;
            $record->locked_until = null;
            if (! $record->assigned_to) {
                $record->assigned_to = $user->getKey();
            }

            $statusClass = $record instanceof Lead ? LeadStatus::class : CollectionStatus::class;
            $status = $statusClass::tryFrom($outcome->record_status);

            switch ($outcome->effect) {
                case OutcomeEffect::Retry:
                    if ($record->attempts >= $record->maxAttempts()) {
                        $record->status = $statusClass::MaxAttempts;
                        $record->next_call_at = null;
                    } else {
                        $record->status = $status ?? $statusClass::InProgress;
                        $record->next_call_at = now()->addMinutes($record->retryAfterMinutes());
                    }
                    break;

                case OutcomeEffect::Callback:
                    $record->status = $statusClass::Callback;
                    $record->next_call_at = $callbackAt;
                    break;

                case OutcomeEffect::Positive:
                    $record->status = $status ?? ($record instanceof Lead ? LeadStatus::Converted : CollectionStatus::Closed);
                    $record->next_call_at = null;
                    if ($record instanceof Lead) {
                        $record->converted_at = now();
                    }
                    break;

                case OutcomeEffect::Agreement:
                    $this->createAgreement($record, $attempt, $user, $agreement);
                    $record->status = AgreementType::coerce($agreement['type']) === AgreementType::Arrangement
                        ? CollectionStatus::Arrangement : CollectionStatus::Promise;
                    $record->next_call_at = null;
                    break;

                case OutcomeEffect::Closed:
                    $record->status = $status ?? $statusClass::Closed;
                    $record->next_call_at = null;
                    if ($record instanceof CollectionFile) {
                        $record->closed_at = now();
                    }
                    break;
            }

            $record->save();

            return $attempt;
        });
    }

    protected function createAgreement(CollectionFile $file, CallAttempt $attempt, User $user, array $data): PaymentAgreement
    {
        $type = AgreementType::coerce($data['type']);
        $total = round((float) $data['total_amount'], 2);
        if ($total <= 0) {
            throw new InvalidArgumentException(__('Het afgesproken bedrag moet groter zijn dan 0.'));
        }

        $installments = $type === AgreementType::Promise ? 1 : max(1, (int) ($data['installments'] ?? 1));
        $installmentAmount = $type === AgreementType::Promise
            ? $total
            : round((float) ($data['installment_amount'] ?? 0) ?: $total / $installments, 2);

        $file->agreements()->where('status', 'active')->update(['status' => 'replaced']);

        $agreement = $file->agreements()->create([
            'call_attempt_id' => $attempt->getKey(),
            'type' => $type,
            'total_amount' => $total,
            'installments' => $installments,
            'installment_amount' => $installmentAmount,
            'interval' => $data['interval'] ?? 'monthly',
            'first_payment_date' => $data['first_payment_date'],
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
            'user_id' => $user->getKey(),
        ]);
        $agreement->buildTerms();

        return $agreement;
    }
}
