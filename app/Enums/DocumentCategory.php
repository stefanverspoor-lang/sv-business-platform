<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentCategory: string implements HasLabel
{
    case Contract = 'contract';
    case Quote = 'quote';
    case Invoice = 'invoice';
    case Correspondence = 'correspondence';
    case Data = 'data';
    case Image = 'image';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contract => __('Contract'),
            self::Quote => __('Offerte'),
            self::Invoice => __('Factuur'),
            self::Correspondence => __('Correspondentie'),
            self::Data => __('Bestand (Excel/CSV)'),
            self::Image => __('Afbeelding'),
            self::Other => __('Overig'),
        };
    }
}
