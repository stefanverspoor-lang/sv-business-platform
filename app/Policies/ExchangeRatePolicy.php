<?php

namespace App\Policies;

class ExchangeRatePolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'finance.manage';
    }
}
