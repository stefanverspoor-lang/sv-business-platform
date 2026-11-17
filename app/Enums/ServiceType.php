<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel
{
    case Telemarketing = 'telemarketing';
    case PreCollection = 'pre_collection';
    case Website = 'website';
    case DataEntry = 'data_entry';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Telemarketing => __('Telemarketing'),
            self::PreCollection => __('Telefonische pre-incasso'),
            self::Website => __('Website'),
            self::DataEntry => __('Data-entry'),
            self::Other => __('Overig'),
        };
    }
}
