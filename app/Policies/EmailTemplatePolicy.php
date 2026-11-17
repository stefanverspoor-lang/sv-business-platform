<?php

namespace App\Policies;

class EmailTemplatePolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'templates.manage';
    }
}
