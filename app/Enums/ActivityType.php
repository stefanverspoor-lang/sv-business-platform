<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ActivityType: string implements HasColor, HasIcon, HasLabel
{
    case Note = 'note';
    case Call = 'call';
    case Meeting = 'meeting';
    case EmailIn = 'email_in';
    case EmailOut = 'email_out';
    case Task = 'task';
    case Document = 'document';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Note => __('Notitie'),
            self::Call => __('Telefoongesprek'),
            self::Meeting => __('Afspraak'),
            self::EmailIn => __('E-mail ontvangen'),
            self::EmailOut => __('E-mail verstuurd'),
            self::Task => __('Taak'),
            self::Document => __('Document'),
            self::System => __('Systeem'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Note => 'gray',
            self::Call, self::Meeting => 'primary',
            self::EmailIn, self::EmailOut => 'info',
            self::Task => 'warning',
            self::Document, self::System => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Note => Heroicon::OutlinedPencilSquare,
            self::Call => Heroicon::OutlinedPhone,
            self::Meeting => Heroicon::OutlinedCalendarDays,
            self::EmailIn => Heroicon::OutlinedInboxArrowDown,
            self::EmailOut => Heroicon::OutlinedPaperAirplane,
            self::Task => Heroicon::OutlinedCheckCircle,
            self::Document => Heroicon::OutlinedDocument,
            self::System => Heroicon::OutlinedCog6Tooth,
        };
    }

    /** Typen die een medewerker zelf kan vastleggen. */
    public static function manual(): array
    {
        return [self::Note, self::Call, self::Meeting];
    }
}
