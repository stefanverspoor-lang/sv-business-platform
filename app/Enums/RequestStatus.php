<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequestStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InProgress => __('Bezig'),
            self::Done => __('Afgerond'),
            self::Rejected => __('Afgewezen'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'warning',
            self::Done => 'success',
            self::Rejected => 'gray',
        };
    }
}
