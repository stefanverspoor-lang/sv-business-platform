<?php

namespace App\Models\Concerns;

/**
 * Model dat in de database van een bedrijf staat (SV of YES).
 * De verbinding "tenant" wijst altijd naar de database van het actieve bedrijf.
 */
trait BelongsToCompanyDatabase
{
    public function initializeBelongsToCompanyDatabase(): void
    {
        $this->setConnection('tenant');
    }
}
