<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequestType: string implements HasColor, HasLabel
{
    case Change = 'change';
    case Support = 'support';

    public function getLabel(): string
    {
        return match ($this) {
            self::Change => __('Wijzigingsverzoek'),
            self::Support => __('Support'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Change => 'info',
            self::Support => 'warning',
        };
    }
}
