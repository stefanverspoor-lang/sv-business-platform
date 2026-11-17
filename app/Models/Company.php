<?php

namespace App\Models;

use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model implements HasCurrentTenantLabel, HasName
{
    use HasFactory;

    protected $connection = 'central';

    protected $fillable = [
        'name', 'slug', 'connection', 'default_currency', 'default_locale', 'timezone',
        'legal_name', 'registration_number', 'vat_number', 'country', 'address_line',
        'postal_code', 'city', 'email', 'phone', 'website', 'primary_color', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return __('Actief bedrijf');
    }
}
