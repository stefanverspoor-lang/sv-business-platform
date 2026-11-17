<?php

namespace App\Models;

use App\Enums\PricingModel;
use App\Enums\ServiceType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Een dienst die een klant afneemt, met de prijsafspraak. */
class ClientService extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    protected $fillable = [
        'client_id', 'contract_id', 'service', 'description', 'pricing_model', 'price',
        'currency', 'start_date', 'end_date', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'service' => ServiceType::class,
            'pricing_model' => PricingModel::class,
            'price' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
