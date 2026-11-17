<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Concept'),
            self::Active => __('Actief'),
            self::Paused => __('Gepauzeerd'),
            self::Closed => __('Afgesloten'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Closed => 'gray',
        };
    }
}
