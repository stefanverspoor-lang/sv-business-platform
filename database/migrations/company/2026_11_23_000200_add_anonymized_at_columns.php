<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AVG: markeert leads en dossiers waarvan de persoonsgegevens zijn gewist. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leads', 'collection_files'] as $table) {
            if (! Schema::hasColumn($table, 'anonymized_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->timestamp('anonymized_at')->nullable()->index());
            }
        }
    }

    public function down(): void
    {
        foreach (['leads', 'collection_files'] as $table) {
            if (Schema::hasColumn($table, 'anonymized_at')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_anonymized_at_index');
                    $t->dropColumn('anonymized_at');
                });
            }
        }
    }
};
