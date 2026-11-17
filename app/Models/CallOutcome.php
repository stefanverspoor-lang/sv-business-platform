<?php

namespace App\Models;

use App\Enums\OutcomeEffect;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Gespreksresultaat, per module (telemarketing of pre-incasso). Zelf aan te vullen. */
class CallOutcome extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    public const MODULE_TELEMARKETING = 'telemarketing';

    public const MODULE_COLLECTION = 'collection';

    protected $fillable = ['module', 'code', 'label', 'label_en', 'effect', 'record_status', 'counts_as_contact', 'is_active', 'sort', 'color'];

    protected function casts(): array
    {
        return ['effect' => OutcomeEffect::class, 'counts_as_contact' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function displayLabel(): string
    {
        return app()->getLocale() === 'en' && $this->label_en ? $this->label_en : $this->label;
    }

    public function scopeFor(Builder $query, string $module): Builder
    {
        return $query->where('module', $module)->where('is_active', true)->orderBy('sort');
    }
}
