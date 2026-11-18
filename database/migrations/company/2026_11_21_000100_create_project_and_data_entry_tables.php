<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4: websiteprojecten, data-entry-opdrachten en urenregistratie.
 *
 * Websites blijven op de hosting van de klant; het CRM bewaart alleen de
 * gegevens. Wachtwoorden worden versleuteld opgeslagen (APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status', 30)->default('new')->index();
            $table->string('website_url')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('price', 12, 2)->nullable();          // bouwprijs
            $table->char('currency', 3)->default('EUR');
            $table->decimal('maintenance_fee', 12, 2)->nullable(); // per maand
            $table->date('maintenance_since')->nullable();
            $table->text('maintenance_terms')->nullable();         // onderhoudsafspraken
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('registrar')->nullable();
            $table->string('registrant')->nullable();          // op wiens naam
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->boolean('auto_renew')->default(true);
            $table->decimal('yearly_cost', 12, 2)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->text('login_url')->nullable();
            $table->text('username')->nullable();               // versleuteld
            $table->text('password')->nullable();               // versleuteld
            $table->text('notes')->nullable();                  // versleuteld
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_hostings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('plan')->nullable();
            $table->text('panel_url')->nullable();
            $table->text('username')->nullable();               // versleuteld
            $table->text('password')->nullable();               // versleuteld
            $table->text('ftp_details')->nullable();            // versleuteld
            $table->text('database_details')->nullable();       // versleuteld
            $table->date('renewal_date')->nullable();
            $table->decimal('monthly_cost', 12, 2)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->boolean('paid_by_client')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('project_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('change');      // change / support
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');      // open, in_progress, done, rejected
            $table->string('requested_by')->nullable();         // naam bij de klant
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->boolean('billable')->default(true);
            $table->decimal('price', 12, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('data_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_service_id')->nullable()->constrained()->nullOnDelete(); // prijsafspraak
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->unsignedInteger('total_records')->default(0);
            $table->date('deadline')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('data_job_user', function (Blueprint $table) {
            $table->foreignId('data_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->primary(['data_job_id', 'user_id']);
        });

        // Uren voor projecten, verzoeken en data-entry; bij data-entry ook de productie.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('timeable');
            $table->unsignedBigInteger('user_id')->index();
            $table->date('date')->index();
            $table->unsignedInteger('minutes');
            $table->text('description')->nullable();
            $table->boolean('billable')->default(true);
            $table->unsignedInteger('records_done')->nullable();
            $table->unsignedInteger('errors_found')->nullable();
            $table->unsignedInteger('corrections')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['time_entries', 'data_job_user', 'data_jobs', 'project_requests', 'project_hostings', 'project_domains', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
