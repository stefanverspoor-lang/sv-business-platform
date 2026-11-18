<?php

namespace App\Policies;

use App\Models\DataJob;
use App\Models\User;

/** Data-entrymedewerkers zien alleen opdrachten waaraan ze zijn toegewezen. */
class DataJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('data_jobs.view_all') || $user->can('data_jobs.manage') || $user->hasRole(\App\Support\Permissions\Roles::DATA_ENTRY);
    }

    public function view(User $user, DataJob $job): bool
    {
        return $user->can('data_jobs.view_all') || $user->can('data_jobs.manage') || in_array($user->getKey(), $job->assigneeIds(), true);
    }

    public function create(User $user): bool
    {
        return $user->can('data_jobs.manage');
    }

    public function update(User $user, DataJob $job): bool
    {
        return $user->can('data_jobs.manage');
    }

    /** Voortgang en uren registreren. */
    public function logProgress(User $user, DataJob $job): bool
    {
        return $user->can('data_jobs.manage') || in_array($user->getKey(), $job->assigneeIds(), true);
    }

    public function delete(User $user, DataJob $job): bool
    {
        return $user->can('data_jobs.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('data_jobs.manage');
    }

    public function restore(User $user, DataJob $job): bool
    {
        return $user->can('data_jobs.manage');
    }
}
