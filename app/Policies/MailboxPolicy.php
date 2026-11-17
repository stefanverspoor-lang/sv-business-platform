<?php

namespace App\Policies;

class MailboxPolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'mailboxes.manage';
    }
}
