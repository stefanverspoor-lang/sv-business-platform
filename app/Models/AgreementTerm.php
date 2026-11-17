<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;

class AgreementTerm extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['sequence', 'due_date', 'amount'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2'];
    }
}
