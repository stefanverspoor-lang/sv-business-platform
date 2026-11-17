<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Geeft een medewerker toegang tot één klant (later ook project, campagne, dossier).
 */
class AccessGrant extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    protected $fillable = ['user_id', 'grantable_type', 'grantable_id', 'granted_by'];

    public function grantable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): ?User
    {
        return User::find($this->user_id);
    }
}
