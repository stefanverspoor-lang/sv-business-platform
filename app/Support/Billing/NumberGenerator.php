<?php

namespace App\Support\Billing;

use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Doorlopende nummering per bedrijf, per soort en per jaar, zonder gaten:
 * een nummer wordt pas uitgegeven bij "definitief maken".
 */
class NumberGenerator
{
    public const PREFIX_SETTING = ['invoice' => 'invoice_prefix', 'credit_note' => 'credit_prefix', 'quote' => 'quote_prefix'];

    public function next(string $type, int $year): string
    {
        $number = DB::connection('tenant')->transaction(function () use ($type, $year) {
            $table = DB::connection('tenant')->table('number_sequences');
            $row = $table->where(['type' => $type, 'year' => $year])->lockForUpdate()->first();
            if (! $row) {
                $table->insert(['type' => $type, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $row = $table->where(['type' => $type, 'year' => $year])->lockForUpdate()->first();
            }
            $next = (int) $row->last_number + 1;
            $table->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });

        $prefix = (string) BillingSettings::get(self::PREFIX_SETTING[$type]);
        $digits = max(1, (int) BillingSettings::get('number_digits'));

        return $prefix.$year.'-'.str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
    }
}
