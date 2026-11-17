<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PricingModel: string implements HasLabel
{
    case PerHour = 'per_hour';
    case PerDay = 'per_day';
    case PerRecord = 'per_record';
    case PerProject = 'per_project';
    case Monthly = 'monthly';
    case Commission = 'commission';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerHour => __('Per uur'),
            self::PerDay => __('Per dag'),
            self::PerRecord => __('Per record'),
            self::PerProject => __('Per project'),
            self::Monthly => __('Vast maandbedrag'),
            self::Commission => __('Commissie'),
            self::Custom => __('Eigen prijsafspraak'),
        };
    }
}
