<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    protected $fillable = [
        'client_id', 'title', 'status', 'start_date', 'end_date', 'notice_period_days',
        'auto_renew', 'value', 'currency', 'agreements', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'auto_renew' => 'boolean',
            'value' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ClientService::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
