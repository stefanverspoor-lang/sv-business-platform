<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CommissionType: string implements HasLabel
{
    case PercentPaid = 'percent_paid';
    case FixedPerSuccess = 'fixed_per_success';
    case PercentOriginal = 'percent_original';

    public function getLabel(): string
    {
        return match ($this) {
            self::PercentPaid => __('Percentage van betaald bedrag'),
            self::FixedPerSuccess => __('Vast bedrag per geslaagd dossier'),
            self::PercentOriginal => __('Percentage van oorspronkelijk bedrag'),
        };
    }
}
