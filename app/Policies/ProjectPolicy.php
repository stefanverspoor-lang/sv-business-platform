<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/** Projectmedewerkers zien en bewerken alleen hun eigen projecten. */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view_all') || $user->can('projects.manage') || $user->hasRole(\App\Support\Permissions\Roles::PROJECT);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view_all') || $user->can('projects.manage') || $project->assigned_to === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can('projects.manage');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.manage') || $project->assigned_to === $user->getKey();
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('projects.manage');
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->can('projects.manage');
    }
}
