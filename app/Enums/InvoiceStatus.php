<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Concept'),
            self::Open => __('Openstaand'),
            self::PartiallyPaid => __('Deels betaald'),
            self::Paid => __('Betaald'),
            self::Cancelled => __('Vervallen'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'warning',
            self::PartiallyPaid => 'info',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** Nog (deels) te betalen. */
    public static function outstanding(): array
    {
        return [self::Open, self::PartiallyPaid];
    }
}
