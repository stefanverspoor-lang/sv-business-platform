<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OutcomeEffect: string implements HasLabel
{
    case Retry = 'retry';
    case Callback = 'callback';
    case Positive = 'positive';
    case Agreement = 'agreement';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Retry => __('Opnieuw proberen (telt als poging)'),
            self::Callback => __('Terugbellen op afgesproken tijd'),
            self::Positive => __('Geslaagd (conversie)'),
            self::Agreement => __('Betalingsafspraak vastleggen'),
            self::Closed => __('Afsluiten'),
        };
    }
}
