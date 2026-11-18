<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Card = 'card';
    case Paypal = 'paypal';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => __('Bankoverschrijving'),
            self::Cash => __('Contant'),
            self::Card => __('Pin / creditcard'),
            self::Paypal => __('PayPal'),
            self::Other => __('Anders'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bank => 'gray',
            self::Cash => 'gray',
            self::Card => 'gray',
            self::Paypal => 'gray',
            self::Other => 'gray',
        };
    }
}
