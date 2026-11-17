<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Logboek van een Excel/CSV-import (leads, dossiers of betalingen). */
class DataImport extends Model
{
    use BelongsToCompanyDatabase;

    public const TYPE_LEADS = 'leads';

    public const TYPE_COLLECTION = 'collection_files';

    public const TYPE_PAYMENTS = 'payments';

    protected $fillable = ['type', 'client_id', 'campaign_id', 'path', 'original_name', 'status', 'total_rows',
        'imported', 'duplicates', 'failed', 'messages', 'user_id'];

    protected function casts(): array
    {
        return ['messages' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_LEADS => __('Leads'),
            self::TYPE_COLLECTION => __('Dossiers'),
            self::TYPE_PAYMENTS => __('Betalingen'),
            default => $this->type,
        };
    }
}
