<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('finance.view') || $user->can('finance.manage');
    }

    public function view(User $user, Expense $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('finance.manage');
    }

    public function update(User $user, Expense $record): bool
    {
        return $user->can('finance.manage');
    }

    public function delete(User $user, Expense $record): bool
    {
        return $user->can('finance.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('finance.manage');
    }

    public function restore(User $user, Expense $record): bool
    {
        return $user->can('finance.manage');
    }
}
