<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Schrijft belangrijke gebruikersacties naar de centrale audit-log.
 * Een fout bij het loggen mag het werk van de gebruiker nooit blokkeren.
 */
class Audit
{
    /** Velden die nooit in de log terechtkomen. */
    protected const HIDDEN = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    public static function log(string $event, ?Model $subject = null, array $changes = [], ?int $userId = null): void
    {
        try {
            $request = request();

            AuditLog::create([
                'user_id' => $userId ?? auth()->id(),
                'company_id' => app(TenantManager::class)->current()?->getKey(),
                'event' => $event,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'changes' => array_diff_key($changes, array_flip(self::HIDDEN)) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 500) ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
