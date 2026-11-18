<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProjectStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case WaitingForClient = 'waiting_client';
    case Testing = 'testing';
    case Delivered = 'delivered';
    case Maintenance = 'maintenance';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('Nieuw'),
            self::InProgress => __('In behandeling'),
            self::WaitingForClient => __('Wacht op klant'),
            self::Testing => __('Testfase'),
            self::Delivered => __('Opgeleverd'),
            self::Maintenance => __('Onderhoud'),
            self::Cancelled => __('Gestopt'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'warning',
            self::WaitingForClient => 'gray',
            self::Testing => 'info',
            self::Delivered => 'success',
            self::Maintenance => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Projecten waar nog aan gewerkt wordt (voor dashboard en lijsten). */
    public static function running(): array
    {
        return [self::New, self::InProgress, self::WaitingForClient, self::Testing];
    }
}
