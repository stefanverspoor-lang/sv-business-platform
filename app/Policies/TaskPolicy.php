<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // iedereen ziet in elk geval zijn eigen taken
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can('tasks.view_all') || $this->isOwn($user, $task);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can('tasks.manage') || $this->isOwn($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.manage') || $task->created_by === $user->getKey();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('tasks.manage');
    }

    public function restore(User $user, Task $task): bool
    {
        return $user->can('tasks.manage');
    }

    protected function isOwn(User $user, Task $task): bool
    {
        return $task->assigned_to === $user->getKey() || $task->created_by === $user->getKey();
    }
}
