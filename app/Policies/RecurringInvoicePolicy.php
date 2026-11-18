<?php

namespace App\Policies;

use App\Models\RecurringInvoice;
use App\Models\User;

class RecurringInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view') || $user->can('invoices.manage');
    }

    public function view(User $user, RecurringInvoice $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, RecurringInvoice $record): bool
    {
        return $user->can('invoices.manage');
    }

    public function delete(User $user, RecurringInvoice $record): bool
    {
        return $user->can('invoices.manage');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
