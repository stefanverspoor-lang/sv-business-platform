<?php

namespace App\Support\Privacy;

use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\CollectionFile;
use App\Models\Lead;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AVG: wist de persoonsgegevens van een lead of dossier, maar laat de
 * cijfers (status, bedragen, belpogingen, commissie) staan zodat
 * rapportages en facturen blijven kloppen.
 */
class Anonymizer
{
    public const LABEL = '[geanonimiseerd]';

    /** Velden met persoonsgegevens die leeg worden gemaakt. */
    protected const LEAD_FIELDS = ['contact_name', 'phone', 'phone_alt', 'email', 'address_line', 'postal_code', 'city', 'website', 'extra', 'notes'];

    protected const FILE_FIELDS = ['contact_name', 'phone', 'phone_normalized', 'phone_alt', 'email', 'address_line', 'postal_code', 'city', 'extra', 'notes', 'invoice_number'];

    public function lead(Lead $lead, string $reason = 'request'): void
    {
        if ($lead->anonymized_at) {
            return;
        }

        DB::connection($lead->getConnectionName())->transaction(function () use ($lead) {
            $values = array_fill_keys(self::LEAD_FIELDS, null);
            $values['company_name'] = self::LABEL;
            // Bij "niet meer bellen" blijft alleen het genormaliseerde nummer staan,
            // zodat het verzoek om niet gebeld te worden gerespecteerd kan worden.
            if ($lead->status !== LeadStatus::DoNotCall) {
                $values['phone_normalized'] = null;
            }
            if (in_array($lead->status, LeadStatus::open(), true)) {
                $values['status'] = LeadStatus::Closed->value;
                $values['next_call_at'] = null;
            }
            $values['anonymized_at'] = now();
            $values['locked_by'] = null;
            $values['locked_until'] = null;
            Lead::withTrashed()->whereKey($lead->getKey())->toBase()->update($values);
            $this->related($lead, 'lead');
        });

        Audit::log('anonymized', $lead, ['reason' => $reason]);
    }

    public function collectionFile(CollectionFile $file, string $reason = 'request'): void
    {
        if ($file->anonymized_at) {
            return;
        }

        DB::connection($file->getConnectionName())->transaction(function () use ($file) {
            $values = array_fill_keys(self::FILE_FIELDS, null);
            $values['debtor_name'] = self::LABEL;
            $values['anonymized_at'] = now();
            $values['locked_by'] = null;
            $values['locked_until'] = null;
            if (in_array($file->status, CollectionStatus::open(), true)) {
                $values['status'] = CollectionStatus::Closed->value;
                $values['closed_at'] = now();
                $values['next_call_at'] = null;
            }
            CollectionFile::withTrashed()->whereKey($file->getKey())->toBase()->update($values);
            $this->related($file, 'collection_file');
        });

        Audit::log('anonymized', $file, ['reason' => $reason]);
    }

    /** Gespreksnotities, nummers en tijdlijnteksten bij het record wissen. */
    protected function related(Model $record, string $morph): void
    {
        CallAttempt::query()->where('callable_type', $morph)->where('callable_id', $record->getKey())
            ->update(['phone' => null, 'notes' => null]);

        Activity::query()->where('subject_type', $morph)->where('subject_id', $record->getKey())
            ->update(['body' => null, 'title' => self::LABEL]);

        // Oude wijzigingen in de audit-log kunnen persoonsgegevens bevatten.
        AuditLog::query()->where('subject_type', $record::class)->where('subject_id', $record->getKey())
            ->whereNotNull('changes')->update(['changes' => null]);
    }
}
