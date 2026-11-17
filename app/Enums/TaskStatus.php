<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('Nieuw'),
            self::InProgress => __('Bezig'),
            self::Waiting => __('Wacht op reactie'),
            self::Done => __('Afgerond'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'warning',
            self::Waiting => 'gray',
            self::Done => 'success',
        };
    }
}
