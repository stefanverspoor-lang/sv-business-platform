<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DebtorType: string implements HasLabel
{
    case Private = 'private';
    case Business = 'business';

    public function getLabel(): string
    {
        return match ($this) {
            self::Private => __('Particulier'),
            self::Business => __('Zakelijk'),
        };
    }
}
