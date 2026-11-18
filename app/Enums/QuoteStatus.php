<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum QuoteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Invoiced = 'invoiced';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Concept'),
            self::Sent => __('Verstuurd'),
            self::Accepted => __('Geaccepteerd'),
            self::Declined => __('Afgewezen'),
            self::Expired => __('Verlopen'),
            self::Invoiced => __('Gefactureerd'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Declined => 'danger',
            self::Expired => 'gray',
            self::Invoiced => 'success',
        };
    }
}
