<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LeadStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Callback = 'callback';
    case Converted = 'converted';
    case NotInterested = 'not_interested';
    case Invalid = 'invalid';
    case DoNotCall = 'do_not_call';
    case MaxAttempts = 'max_attempts';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('Nieuw'),
            self::InProgress => __('In behandeling'),
            self::Callback => __('Terugbellen'),
            self::Converted => __('Geconverteerd'),
            self::NotInterested => __('Geen interesse'),
            self::Invalid => __('Ongeldig nummer'),
            self::DoNotCall => __('Niet meer bellen'),
            self::MaxAttempts => __('Maximum belpogingen'),
            self::Closed => __('Afgesloten'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'warning',
            self::Callback => 'info',
            self::Converted => 'success',
            self::NotInterested => 'danger',
            self::Invalid => 'warning',
            self::DoNotCall => 'danger',
            self::MaxAttempts => 'gray',
            self::Closed => 'gray',
        };
    }

    /** Statussen waarin een lead nog gebeld kan worden. */
    public static function open(): array
    {
        return [self::New, self::InProgress, self::Callback];
    }
}
