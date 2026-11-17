<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: telemarketing en telefonische pre-incasso.
 *
 * Opdrachtgevers zijn gewone klanten (clients). Campagnes en dossiers horen
 * altijd bij één opdrachtgever. Belpogingen en gespreksresultaten zijn
 * gedeeld tussen leads en dossiers, zodat er één belscherm is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_outcomes', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20);          // telemarketing / collection
            $table->string('code', 40);
            $table->string('label');
            $table->string('label_en')->nullable();
            $table->string('effect', 20);          // retry, callback, positive, agreement, closed
            $table->string('record_status', 30);   // status die de lead/het dossier krijgt
            $table->boolean('counts_as_contact')->default(false); // iemand gesproken
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->string('color', 20)->default('gray');
            $table->timestamps();
            $table->unique(['module', 'code']);
        });

        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);            // leads, collection_files, payments
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('path');
            $table->string('original_name');
            $table->string('status', 20)->default('done');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('messages')->nullable();  // per regel wat er mis was
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete(); // opdrachtgever
            $table->string('name');
            $table->string('type', 30)->default('appointments');
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('retry_after_minutes')->default(120);
            $table->string('default_country', 2)->default('NL');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('description')->nullable();
            $table->longText('script')->nullable(); // belscript
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('phone_alt', 40)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('address_line')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('website')->nullable();
            $table->json('extra')->nullable();       // overige kolommen uit de import
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_called_at')->nullable();
            $table->timestamp('next_call_at')->nullable()->index();
            $table->foreignId('last_outcome_id')->nullable()->constrained('call_outcomes')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('data_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['campaign_id', 'phone_normalized']);
        });

        Schema::create('collection_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete(); // opdrachtgever
            $table->string('file_number', 100);       // dossiernummer van de opdrachtgever
            $table->string('debtor_type', 10)->default('private'); // private / business
            $table->string('debtor_name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('phone_alt', 40)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('address_line')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('original_amount', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->json('extra')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(6);
            $table->timestamp('last_called_at')->nullable();
            $table->timestamp('next_call_at')->nullable()->index();
            $table->foreignId('last_outcome_id')->nullable()->constrained('call_outcomes')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            // Uitkomst volgens de betaalimport van de opdrachtgever
            $table->string('payment_status', 20)->default('unknown'); // unknown, unpaid, partial, paid
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->timestamp('commission_calculated_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('data_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['client_id', 'file_number']);
        });

        Schema::create('call_attempts', function (Blueprint $table) {
            $table->id();
            $table->morphs('callable');               // lead / collection_file
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreignId('call_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('called_at')->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                // promise (in één keer) / arrangement (regeling)
            $table->decimal('total_amount', 12, 2);
            $table->unsignedSmallInteger('installments')->default(1);
            $table->decimal('installment_amount', 12, 2);
            $table->string('interval', 20)->default('monthly');
            $table->date('first_payment_date');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active / replaced / cancelled
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('agreement_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_agreement_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('payment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_import_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('paid_amount', 12, 2);
            $table->date('paid_at')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete(); // opdrachtgever
            $table->string('name');
            $table->string('type', 30);   // percent_paid, fixed_per_success, percent_original
            $table->decimal('rate', 12, 4); // percentage of vast bedrag
            $table->char('currency', 3)->default('EUR');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->seedOutcomes();
    }

    /** Standaard gespreksresultaten uit de briefing; zelf aan te vullen in Beheer. */
    protected function seedOutcomes(): void
    {
        $now = now();
        $rows = [
            // module, code, label, label_en, effect, record_status, contact, color
            ['collection', 'no_answer', 'Geen gehoor', 'No answer', 'retry', 'in_progress', false, 'gray'],
            ['collection', 'voicemail', 'Voicemail', 'Voicemail', 'retry', 'in_progress', false, 'gray'],
            ['collection', 'callback', 'Terugbellen', 'Call back', 'callback', 'callback', true, 'info'],
            ['collection', 'promise', 'Betalingsbelofte', 'Payment promise', 'agreement', 'promise', true, 'success'],
            ['collection', 'arrangement', 'Betalingsregeling', 'Payment plan', 'agreement', 'arrangement', true, 'success'],
            ['collection', 'disputed', 'Dossier betwist', 'Disputed', 'closed', 'disputed', true, 'danger'],
            ['collection', 'unwilling', 'Geen betalingsbereidheid', 'Unwilling to pay', 'closed', 'unwilling', true, 'danger'],
            ['collection', 'wrong_number', 'Verkeerd telefoonnummer', 'Wrong number', 'closed', 'wrong_number', false, 'warning'],
            ['collection', 'closed', 'Dossier afgesloten', 'File closed', 'closed', 'closed', false, 'gray'],
            ['telemarketing', 'no_answer', 'Geen gehoor', 'No answer', 'retry', 'in_progress', false, 'gray'],
            ['telemarketing', 'voicemail', 'Voicemail', 'Voicemail', 'retry', 'in_progress', false, 'gray'],
            ['telemarketing', 'busy', 'In gesprek', 'Busy', 'retry', 'in_progress', false, 'gray'],
            ['telemarketing', 'callback', 'Terugbellen', 'Call back', 'callback', 'callback', true, 'info'],
            ['telemarketing', 'appointment', 'Afspraak gemaakt', 'Appointment set', 'positive', 'converted', true, 'success'],
            ['telemarketing', 'sale', 'Verkocht', 'Sale', 'positive', 'converted', true, 'success'],
            ['telemarketing', 'qualified', 'Gekwalificeerd', 'Qualified', 'positive', 'converted', true, 'success'],
            ['telemarketing', 'survey_done', 'Enquête afgenomen', 'Survey completed', 'positive', 'converted', true, 'success'],
            ['telemarketing', 'not_interested', 'Geen interesse', 'Not interested', 'closed', 'not_interested', true, 'danger'],
            ['telemarketing', 'wrong_number', 'Verkeerd telefoonnummer', 'Wrong number', 'closed', 'invalid', false, 'warning'],
            ['telemarketing', 'do_not_call', 'Niet meer bellen', 'Do not call', 'closed', 'do_not_call', true, 'danger'],
        ];

        foreach ($rows as $i => [$module, $code, $label, $en, $effect, $status, $contact, $color]) {
            DB::table('call_outcomes')->insert([
                'module' => $module, 'code' => $code, 'label' => $label, 'label_en' => $en,
                'effect' => $effect, 'record_status' => $status, 'counts_as_contact' => $contact,
                'is_system' => true, 'is_active' => true, 'sort' => ($i + 1) * 10, 'color' => $color,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['commission_models', 'payment_results', 'agreement_terms', 'payment_agreements', 'call_attempts',
            'collection_files', 'leads', 'campaigns', 'data_imports', 'call_outcomes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
