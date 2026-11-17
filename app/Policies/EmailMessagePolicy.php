<?php

namespace App\Policies;

use App\Models\EmailMessage;
use App\Models\User;

class EmailMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // zonder emails.view_all alleen toegewezen of zelf verstuurde mail
    }

    public function view(User $user, EmailMessage $message): bool
    {
        return $user->can('emails.view_all')
            || $message->assigned_to === $user->getKey()
            || $message->sent_by === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can('emails.send');
    }

    public function update(User $user, EmailMessage $message): bool
    {
        return $this->view($user, $message);
    }

    public function delete(User $user, EmailMessage $message): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
