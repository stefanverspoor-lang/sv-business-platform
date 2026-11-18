<?php

namespace App\Policies;

class FinanceCategoryPolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'billing.settings';
    }
}
