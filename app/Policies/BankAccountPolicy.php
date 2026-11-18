<?php

namespace App\Policies;

class BankAccountPolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'billing.settings';
    }
}
