<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum Recurrence: string implements HasLabel
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('Niet herhalen'),
            self::Daily => __('Dagelijks'),
            self::Weekly => __('Wekelijks'),
            self::Monthly => __('Maandelijks'),
            self::Yearly => __('Jaarlijks'),
        };
    }

    public function next(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Daily => $date->copy()->addDay(),
            self::Weekly => $date->copy()->addWeek(),
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::Yearly => $date->copy()->addYearNoOverflow(),
            self::None => $date->copy(),
        };
    }
}
