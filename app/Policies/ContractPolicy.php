<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('view', $contract->client);
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.manage');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('contracts.manage') && $this->view($user, $contract);
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('contracts.manage') && $this->view($user, $contract);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('contracts.manage');
    }
}
