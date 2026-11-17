<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * E-mailsjabloon met plaatshouders: {klant}, {contactpersoon}, {voornaam}, {afzender}, {bedrijf}.
 */
class EmailTemplate extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    public const PLACEHOLDERS = ['{klant}', '{contactpersoon}', '{voornaam}', '{afzender}', '{bedrijf}'];

    protected $fillable = ['name', 'locale', 'subject', 'body', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array{subject: string, body: string} */
    public function render(array $values): array
    {
        $map = array_combine(self::PLACEHOLDERS, [
            $values['klant'] ?? '', $values['contactpersoon'] ?? '', $values['voornaam'] ?? '',
            $values['afzender'] ?? '', $values['bedrijf'] ?? '',
        ]);

        return ['subject' => strtr($this->subject, $map), 'body' => strtr($this->body, $map)];
    }
}
