<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContractStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Concept'),
            self::Active => __('Lopend'),
            self::Ended => __('Afgelopen'),
            self::Cancelled => __('Opgezegd'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Ended => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
