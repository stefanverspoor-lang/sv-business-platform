<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar met initialen, lokaal gemaakt. Er gaan geen namen naar een externe dienst.
 */
class SvAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim(Filament::getNameForDefaultAvatar($record));
        $initials = collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->map(fn (string $part) => preg_replace('/[^\p{L}\p{N}]/u', '', $part))
            ->filter()
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        $color = $record->primary_color ?? '#1F4D45';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="16" fill="'.e($color).'"/>'
            .'<text x="32" y="41" font-family="Figtree, Arial, sans-serif" font-size="26" font-weight="600" '
            .'fill="#F4EFE7" text-anchor="middle">'.e($initials ?: '?').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
