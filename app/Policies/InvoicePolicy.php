<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view') || $user->can('invoices.manage');
    }

    public function view(User $user, Invoice $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, Invoice $record): bool
    {
        return $user->can('invoices.manage') && $record->isDraft();
    }

    public function delete(User $user, Invoice $record): bool
    {
        return $user->can('invoices.manage') && $record->isDraft();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
