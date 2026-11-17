<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Resources\Pages\EditRecord;

class EditMailbox extends EditRecord
{
    protected static string $resource = MailboxResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Resources\Mailboxes\MailboxResource::testAction(), \Filament\Actions\DeleteAction::make()];
    }
}
