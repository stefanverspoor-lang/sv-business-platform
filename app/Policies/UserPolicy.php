<?php

namespace App\Policies;

use App\Models\User;
use Filament\Facades\Filament;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage') && $this->sharesCurrentCompany($model);
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        // Een bedrijfsmanager kan geen superadmin wijzigen.
        return $user->can('users.manage') && ! $model->isSuperadmin() && $this->sharesCurrentCompany($model);
    }

    public function delete(User $user, User $model): bool
    {
        return false; // gebruikers worden gedeactiveerd, niet verwijderd
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function sharesCurrentCompany(User $model): bool
    {
        $company = Filament::getTenant();

        return $company && $model->companies()->whereKey($company->getKey())->exists();
    }
}
