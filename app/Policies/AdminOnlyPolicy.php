<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Voor beheeronderdelen: mailboxen (mailboxes.manage) en sjablonen (templates.manage).
 */
abstract class AdminOnlyPolicy
{
    abstract protected function permission(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->permission());
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->permission());
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission());
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->permission());
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->permission());
    }

    public function deleteAny(User $user): bool
    {
        return $user->can($this->permission());
    }
}
