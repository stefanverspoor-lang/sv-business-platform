<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-mailhandtekening per mailbox, spam-map meesynchroniseren, en meerdere
 * bankrekeningen per bedrijf (te kiezen per factuur).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Elke stap controleert eerst of hij al gedaan is (MariaDB kan DDL niet terugdraaien).
        foreach (['signature' => 'text', 'signature_logo_path' => 'string', 'spam_folder' => 'string'] as $column => $type) {
            if (! Schema::hasColumn('mailboxes', $column)) {
                Schema::table('mailboxes', fn (Blueprint $t) => $t->{$type}($column)->nullable());
            }
        }

        if (! Schema::hasColumn('email_messages', 'is_spam')) {
            Schema::table('email_messages', fn (Blueprint $t) => $t->boolean('is_spam')->default(false)->index());
        }

        $newBankTable = ! Schema::hasTable('bank_accounts');
        if ($newBankTable) {
            Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                         // bijv. "ING (EUR) – Nederland"
            $table->string('account_holder')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('iban', 50)->nullable();          // IBAN of rekeningnummer
            $table->string('bic', 20)->nullable();           // BIC / SWIFT
            $table->char('currency', 3)->nullable();         // voorgesteld bij facturen in deze valuta
            $table->text('extra')->nullable();               // bijv. bankadres, routing/sort code
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            });
        }

        foreach (['invoices', 'recurring_invoices', 'quotes'] as $table) {
            if (! Schema::hasColumn($table, 'bank_account_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('bank_account_id')->nullable()->index());
            }
        }

        // De rekening uit de factuurinstellingen wordt de eerste (standaard)rekening.
        $settings = DB::table('settings')->whereIn('key', ['iban', 'bic', 'bank_name', 'account_holder', 'base_currency'])->pluck('value', 'key')
            ->map(fn ($v) => json_decode((string) $v, true));
        if ($newBankTable && ! empty($settings['iban'])) {
            DB::table('bank_accounts')->insert([
                'name' => trim(($settings['bank_name'] ?? __('Bankrekening')).' '.(! empty($settings['base_currency']) ? '('.$settings['base_currency'].')' : '')),
                'account_holder' => $settings['account_holder'] ?? null,
                'bank_name' => $settings['bank_name'] ?? null,
                'iban' => $settings['iban'],
                'bic' => $settings['bic'] ?? null,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'recurring_invoices', 'quotes'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table.'_bank_account_id_index');
                $t->dropColumn('bank_account_id');
            });
        }
        Schema::dropIfExists('bank_accounts');
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex('email_messages_is_spam_index');
            $table->dropColumn('is_spam');
        });
        Schema::table('mailboxes', fn (Blueprint $t) => $t->dropColumn(['signature', 'signature_logo_path', 'spam_folder']));
    }
};
