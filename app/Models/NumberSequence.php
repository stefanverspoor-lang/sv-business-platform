<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['type', 'year', 'last_number'];
}
