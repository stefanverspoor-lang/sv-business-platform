<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bedrijfsdatabase (SV of YES). Deze migraties draaien op elke
 * bedrijfsdatabase afzonderlijk via: php artisan crm:migrate-companies
 * Gebruikers-id's verwijzen naar de centrale database; daarom zonder
 * foreign key naar users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('website')->nullable();
            $table->string('address_line')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('preferred_locale', 5)->default('nl');
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('account_manager_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('mobile', 40)->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Toegang per klant (later ook per project, campagne en dossier)
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->morphs('grantable');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'grantable_type', 'grantable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('clients');
    }
};
