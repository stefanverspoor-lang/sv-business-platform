<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BillingInterval: string implements HasColor, HasLabel
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => __('Per maand'),
            self::Quarterly => __('Per kwartaal'),
            self::HalfYearly => __('Per halfjaar'),
            self::Yearly => __('Per jaar'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Monthly => 'gray',
            self::Quarterly => 'gray',
            self::HalfYearly => 'gray',
            self::Yearly => 'gray',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }
}
