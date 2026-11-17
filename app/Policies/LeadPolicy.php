<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/** Medewerkers zien en bewerken alleen wat aan hen is toegewezen. */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('calls.make') || $user->can('leads.view_all') || $user->can('campaigns.manage');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can('leads.view_all') || $user->can('campaigns.manage') || $lead->assigned_to === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can('campaigns.manage');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can('campaigns.manage') || $lead->assigned_to === $user->getKey();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('campaigns.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('campaigns.manage');
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $user->can('campaigns.manage');
    }
}
