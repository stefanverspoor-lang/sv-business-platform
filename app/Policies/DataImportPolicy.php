<?php

namespace App\Policies;

use App\Models\DataImport;
use App\Models\User;

class DataImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('campaigns.manage') || $user->can('collection.manage');
    }

    public function view(User $user, DataImport $import): bool
    {
        return $import->type === DataImport::TYPE_LEADS ? $user->can('campaigns.manage') : $user->can('collection.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DataImport $import): bool
    {
        return false;
    }

    public function delete(User $user, DataImport $import): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
