<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hostinggegevens van de klant (de website staat daar, niet bij ons). Inloggegevens versleuteld. */
class ProjectHosting extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['provider', 'plan', 'panel_url', 'username', 'password', 'ftp_details', 'database_details',
        'renewal_date', 'monthly_cost', 'currency', 'paid_by_client', 'notes'];

    protected $hidden = ['username', 'password', 'ftp_details', 'database_details'];

    protected function casts(): array
    {
        return [
            'renewal_date' => 'date', 'monthly_cost' => 'decimal:2', 'paid_by_client' => 'boolean',
            'username' => 'encrypted', 'password' => 'encrypted', 'ftp_details' => 'encrypted', 'database_details' => 'encrypted',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
