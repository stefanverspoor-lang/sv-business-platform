<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CollectionStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Callback = 'callback';
    case Promise = 'promise';
    case Arrangement = 'arrangement';
    case Disputed = 'disputed';
    case Unwilling = 'unwilling';
    case WrongNumber = 'wrong_number';
    case MaxAttempts = 'max_attempts';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('Nieuw'),
            self::InProgress => __('In behandeling'),
            self::Callback => __('Terugbellen'),
            self::Promise => __('Betalingsbelofte'),
            self::Arrangement => __('Betalingsregeling'),
            self::Disputed => __('Betwist'),
            self::Unwilling => __('Geen betalingsbereidheid'),
            self::WrongNumber => __('Verkeerd nummer'),
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
            self::Promise => 'success',
            self::Arrangement => 'success',
            self::Disputed => 'danger',
            self::Unwilling => 'danger',
            self::WrongNumber => 'warning',
            self::MaxAttempts => 'gray',
            self::Closed => 'gray',
        };
    }

    /** Statussen waarin een dossier nog gebeld kan worden. */
    public static function open(): array
    {
        return [self::New, self::InProgress, self::Callback];
    }

    /** Een betalingsafspraak is gemaakt. */
    public static function agreed(): array
    {
        return [self::Promise, self::Arrangement];
    }
}
