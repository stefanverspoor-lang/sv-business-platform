<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Domeinnaam van een project. Inloggegevens versleuteld. */
class ProjectDomain extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['domain', 'registrar', 'registrant', 'registered_at', 'expires_at', 'auto_renew', 'yearly_cost',
        'currency', 'login_url', 'username', 'password', 'notes'];

    protected $hidden = ['username', 'password', 'notes'];

    protected function casts(): array
    {
        return [
            'registered_at' => 'date', 'expires_at' => 'date', 'auto_renew' => 'boolean', 'yearly_cost' => 'decimal:2',
            'username' => 'encrypted', 'password' => 'encrypted', 'notes' => 'encrypted', 'expiry_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Nieuwe verloopdatum = opnieuw herinneren
        static::updated(function (ProjectDomain $domain) {
            if ($domain->wasChanged('expires_at')) {
                static::whereKey($domain->getKey())->update(['expiry_notified_at' => null]);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }
}
