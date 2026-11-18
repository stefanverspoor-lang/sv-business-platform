<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;

/** Iedereen schrijft eigen uren; met time.view_all zie en corrigeer je die van anderen. */
class TimeEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TimeEntry $entry): bool
    {
        return $user->can('time.view_all') || $entry->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TimeEntry $entry): bool
    {
        return $user->can('time.view_all') || ($entry->user_id === $user->getKey() && $entry->date->gte(now()->subDays(14)));
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('time.view_all');
    }
}
