<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;

/** Een betaling zoals de opdrachtgever die heeft gemeld in de betaalimport. */
class PaymentResult extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['collection_file_id', 'data_import_id', 'paid_amount', 'paid_at', 'reference'];

    protected function casts(): array
    {
        return ['paid_amount' => 'decimal:2', 'paid_at' => 'date'];
    }
}
