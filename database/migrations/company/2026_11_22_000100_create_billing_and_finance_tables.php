<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5: facturatie en financiële administratie, per bedrijf.
 *
 * Bedragen staan in de valuta van het document; de wisselkoers naar de
 * basisvaluta van het bedrijf wordt per document vastgelegd, zodat
 * rapportages altijd in één valuta kunnen rekenen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('vat_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 5, 2)->default(0);
            $table->string('kind', 20)->default('standard'); // standard, reduced, zero, exempt, reverse_charge, none
            $table->string('invoice_note')->nullable();       // tekst op de factuur, bijv. "BTW verlegd"
            $table->string('invoice_note_en')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->decimal('rate', 18, 8);   // 1 eenheid valuta = rate × basisvaluta
            $table->date('date');
            $table->string('source')->nullable();
            $table->timestamps();
            $table->unique(['currency', 'base_currency', 'date']);
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);       // invoice, credit_note, quote
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['type', 'year']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 50)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->char('locale', 2)->default('nl');
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('subject')->nullable();
            $table->text('intro')->nullable();
            $table->text('notes')->nullable();
            $table->json('client_snapshot')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('name');
            $table->string('interval', 20)->default('monthly'); // monthly, quarterly, half_yearly, yearly
            $table->date('next_date');
            $table->date('end_date')->nullable();
            $table->char('locale', 2)->default('nl');
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('payment_terms_days')->default(14);
            $table->string('subject')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('auto_finalize')->default(false);
            $table->boolean('auto_send')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_generated_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('invoice');   // invoice / credit_note
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 50)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index(); // draft, open, partially_paid, paid, cancelled
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(14);
            $table->char('locale', 2)->default('nl');
            $table->char('currency', 3)->default('EUR');
            $table->char('base_currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('subject')->nullable();
            $table->string('reference')->nullable();             // inkoopnummer / referentie klant
            $table->text('intro')->nullable();
            $table->text('notes')->nullable();
            $table->json('client_snapshot')->nullable();
            $table->json('company_snapshot')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credited_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('recurring_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Regels van offertes, facturen en terugkerende facturen
        Schema::create('billing_lines', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 20)->nullable();             // uur, stuk, record, maand ...
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->foreignId('vat_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('vat_rate', 5, 2)->default(0);       // vastgelegd op het moment van factureren
            $table->string('vat_label')->nullable();
            $table->decimal('line_total', 14, 2)->default(0);    // exclusief btw
            $table->json('source')->nullable();                  // gefactureerde uren / dossiers
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->string('method', 20)->default('bank');      // bank, cash, card, paypal, other
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                          // expense / income
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('supplier');
            $table->string('invoice_number')->nullable();        // nummer van de inkoopfactuur
            $table->string('description')->nullable();
            $table->foreignId('finance_category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_excl', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description');
            $table->foreignId('finance_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_excl', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Wat al gefactureerd is, zodat niets twee keer op een factuur komt
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
        });
        Schema::table('collection_files', function (Blueprint $table) {
            $table->unsignedBigInteger('commission_invoice_id')->nullable()->index();
        });

        $this->seed();
    }

    protected function seed(): void
    {
        $now = now();
        DB::table('vat_rates')->insert([
            ['name' => 'Geen btw', 'rate' => 0, 'kind' => 'none', 'invoice_note' => null, 'invoice_note_en' => null, 'is_default' => true, 'sort' => 10],
            ['name' => 'Btw 21%', 'rate' => 21, 'kind' => 'standard', 'invoice_note' => null, 'invoice_note_en' => null, 'is_default' => false, 'sort' => 20],
            ['name' => 'Btw 9%', 'rate' => 9, 'kind' => 'reduced', 'invoice_note' => null, 'invoice_note_en' => null, 'is_default' => false, 'sort' => 30],
            ['name' => 'Btw 0%', 'rate' => 0, 'kind' => 'zero', 'invoice_note' => null, 'invoice_note_en' => null, 'is_default' => false, 'sort' => 40],
            ['name' => 'Btw verlegd', 'rate' => 0, 'kind' => 'reverse_charge', 'invoice_note' => 'Btw verlegd naar de afnemer.', 'invoice_note_en' => 'VAT reverse charged to the customer.', 'is_default' => false, 'sort' => 50],
            ['name' => 'Vrijgesteld van btw', 'rate' => 0, 'kind' => 'exempt', 'invoice_note' => 'Vrijgesteld van btw.', 'invoice_note_en' => 'Exempt from VAT.', 'is_default' => false, 'sort' => 60],
        ]);
        DB::table('vat_rates')->update(['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $categories = [
            'expense' => ['Software en abonnementen', 'Telefonie en internet', 'Hosting en domeinen', 'Kantoor', 'Marketing',
                'Reiskosten', 'Freelancers en personeel', 'Bankkosten', 'Advies en administratie', 'Overige kosten'],
            'income' => ['Overige inkomsten', 'Rente', 'Teruggaaf'],
        ];
        foreach ($categories as $type => $names) {
            foreach ($names as $i => $name) {
                DB::table('finance_categories')->insert(['type' => $type, 'name' => $name, 'is_active' => true, 'sort' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('collection_files', fn (Blueprint $t) => $t->dropColumn('commission_invoice_id'));
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropColumn('invoice_id'));
        foreach (['incomes', 'expenses', 'finance_categories', 'invoice_payments', 'billing_lines', 'invoices', 'recurring_invoices',
            'quotes', 'number_sequences', 'exchange_rates', 'vat_rates', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
