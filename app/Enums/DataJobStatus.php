<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DataJobStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('Nieuw'),
            self::InProgress => __('Bezig'),
            self::Review => __('Controle'),
            self::Delivered => __('Opgeleverd'),
            self::Cancelled => __('Gestopt'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'warning',
            self::Review => 'info',
            self::Delivered => 'success',
            self::Cancelled => 'gray',
        };
    }

    public static function running(): array
    {
        return [self::New, self::InProgress, self::Review];
    }
}
