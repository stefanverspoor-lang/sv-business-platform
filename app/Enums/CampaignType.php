<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CampaignType: string implements HasLabel
{
    case Appointments = 'appointments';
    case Sales = 'sales';
    case FollowUp = 'follow_up';
    case Qualification = 'qualification';
    case Survey = 'survey';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Appointments => __('Afspraken maken'),
            self::Sales => __('Verkoop'),
            self::FollowUp => __('Bestaande klanten nabellen'),
            self::Qualification => __('Leads kwalificeren'),
            self::Survey => __('Enquête'),
            self::Other => __('Overig'),
        };
    }
}
