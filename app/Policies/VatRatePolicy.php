<?php

namespace App\Policies;

class VatRatePolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'billing.settings';
    }
}
