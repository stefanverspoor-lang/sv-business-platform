<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceType: string implements HasColor, HasLabel
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Factuur'),
            self::CreditNote => __('Creditnota'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invoice => 'primary',
            self::CreditNote => 'danger',
        };
    }
}
