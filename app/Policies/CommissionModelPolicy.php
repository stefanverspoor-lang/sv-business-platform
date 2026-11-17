<?php

namespace App\Policies;

class CommissionModelPolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'commission.manage';
    }
}
