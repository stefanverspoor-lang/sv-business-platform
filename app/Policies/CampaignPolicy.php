<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('campaigns.manage') || $user->can('leads.view_all') || $user->can('calls.make');
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.manage') || $user->can('leads.view_all')
            || $campaign->leads()->where('assigned_to', $user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('campaigns.manage');
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.manage');
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('campaigns.manage');
    }

    public function restore(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.manage');
    }
}
