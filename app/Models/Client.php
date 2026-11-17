<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use Auditable, BelongsToCompanyDatabase, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'registration_number', 'vat_number', 'email', 'phone',
        'website', 'address_line', 'postal_code', 'city', 'country', 'timezone',
        'preferred_locale', 'status', 'notes', 'account_manager_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['status' => ClientStatus::class];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    public function accessGrants(): MorphMany
    {
        return $this->morphMany(AccessGrant::class, 'grantable');
    }

    /** Medewerker uit de centrale database (geen databasekoppeling over databases heen). */
    public function accountManager(): ?User
    {
        return $this->account_manager_id ? User::find($this->account_manager_id) : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ClientStatus::Active);
    }

    /**
     * Alleen klanten waar de gebruiker toegang toe heeft.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperadmin() || $user->can('clients.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('account_manager_id', $user->getKey())
                ->orWhereHas('accessGrants', fn (Builder $g) => $g->where('user_id', $user->getKey()));
        });
    }
}
