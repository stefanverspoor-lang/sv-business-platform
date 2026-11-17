<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clients.view') || $user->can('clients.view_all');
    }

    public function view(User $user, Client $client): bool
    {
        return Client::query()->visibleTo($user)->whereKey($client->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('clients.update') && $this->view($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can('clients.delete') && $this->view($user, $client);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('clients.delete');
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->can('clients.delete');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('clients.delete');
    }

    public function forceDelete(User $user, Client $client): bool
    {
        return false; // alleen de superadmin (via Gate::before)
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
