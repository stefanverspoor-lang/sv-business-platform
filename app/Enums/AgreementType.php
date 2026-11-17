<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AgreementType: string implements HasLabel
{
    case Promise = 'promise';
    case Arrangement = 'arrangement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Promise => __('Betalingsbelofte (in één keer)'),
            self::Arrangement => __('Betalingsregeling (termijnen)'),
        };
    }

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }
}
