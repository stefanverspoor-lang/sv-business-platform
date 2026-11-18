<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samengestelde indexen voor de zoek- en sorteerpatronen van de wachtrij,
 * dashboards en rapportages, zodat het snel blijft met tienduizenden leads,
 * dossiers en belpogingen.
 */
return new class extends Migration
{
    /** tabel => [indexnaam => kolommen] */
    protected array $indexes = [
        'leads' => [
            'leads_queue_idx' => ['campaign_id', 'status', 'next_call_at'],
            'leads_assignee_status_idx' => ['assigned_to', 'status'],
        ],
        'collection_files' => [
            'cf_queue_idx' => ['client_id', 'status', 'next_call_at'],
            'cf_assignee_status_idx' => ['assigned_to', 'status'],
            'cf_paid_idx' => ['payment_status', 'paid_at'],
            'cf_created_idx' => ['created_at'],
        ],
        'call_attempts' => [
            'ca_type_called_idx' => ['callable_type', 'called_at'],
            'ca_user_called_idx' => ['user_id', 'called_at'],
        ],
        'payment_agreements' => [
            'pa_created_idx' => ['created_at'],
        ],
        'invoices' => [
            'inv_status_due_idx' => ['status', 'due_date'],
            'inv_client_status_idx' => ['client_id', 'status'],
            'inv_issue_idx' => ['issue_date'],
        ],
        'invoice_payments' => [
            'ip_paid_idx' => ['paid_at'],
        ],
        'expenses' => [
            'exp_date_idx' => ['date'],
        ],
        'incomes' => [
            'inc_date_idx' => ['date'],
        ],
        'time_entries' => [
            'te_user_date_idx' => ['user_id', 'date'],
            'te_timeable_date_idx' => ['timeable_type', 'timeable_id', 'date'],
        ],
        'tasks' => [
            'tasks_assignee_status_due_idx' => ['assigned_to', 'status', 'due_at'],
            'tasks_status_due_idx' => ['status', 'due_at'],
        ],
        'email_messages' => [
            'em_direction_read_idx' => ['direction', 'is_read'],
            'em_assignee_read_idx' => ['assigned_to', 'is_read'],
        ],
        'clients' => [
            'clients_status_created_idx' => ['status', 'created_at'],
        ],
        'activities' => [
            'act_client_occurred_idx' => ['client_id', 'occurred_at'],
        ],
        'projects' => [
            'projects_client_status_idx' => ['client_id', 'status'],
        ],
        'data_jobs' => [
            'dj_client_status_idx' => ['client_id', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name) || ! Schema::hasColumns($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
