<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2 (bedrijfsdatabase): tijdlijn, contracten, diensten, documenten en taken.
 * Gebruikers-id's verwijzen naar de centrale database (geen foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Contacthistorie: notities, gesprekken, e-mails, afspraken en systeemgebeurtenissen
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('type', 30)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('draft')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable()->index();
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->text('agreements')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Aangeboden diensten en prijsafspraken per klant
        Schema::create('client_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service', 30)->index();
            $table->string('description')->nullable();
            $table->string('pricing_model', 30);
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('title');
            $table->string('category', 30)->default('other')->index();
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('taskable');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->string('priority', 10)->default('normal')->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('remind_at')->nullable()->index();
            $table->timestamp('reminded_at')->nullable();
            $table->string('recurrence', 10)->default('none');
            $table->foreignId('recurring_parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('client_services');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('activities');
    }
};
