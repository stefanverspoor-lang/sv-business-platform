<?php

namespace App\Policies;

use App\Models\Income;
use App\Models\User;

class IncomePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.view') || $user->can('finance.manage');
    }

    public function view(User $user, Income $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('finance.manage');
    }

    public function update(User $user, Income $record): bool
    {
        return $user->can('finance.manage');
    }

    public function delete(User $user, Income $record): bool
    {
        return $user->can('finance.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('finance.manage');
    }

    public function restore(User $user, Income $record): bool
    {
        return $user->can('finance.manage');
    }
}
