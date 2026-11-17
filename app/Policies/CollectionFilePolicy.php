<?php

namespace App\Policies;

use App\Models\CollectionFile;
use App\Models\User;

/** Medewerkers zien en bewerken alleen wat aan hen is toegewezen. */
class CollectionFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('calls.make') || $user->can('collection.view_all') || $user->can('collection.manage');
    }

    public function view(User $user, CollectionFile $collectionFile): bool
    {
        return $user->can('collection.view_all') || $user->can('collection.manage') || $collectionFile->assigned_to === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can('collection.manage');
    }

    public function update(User $user, CollectionFile $collectionFile): bool
    {
        return $user->can('collection.manage') || $collectionFile->assigned_to === $user->getKey();
    }

    public function delete(User $user, CollectionFile $collectionFile): bool
    {
        return $user->can('collection.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('collection.manage');
    }

    public function restore(User $user, CollectionFile $collectionFile): bool
    {
        return $user->can('collection.manage');
    }
}
