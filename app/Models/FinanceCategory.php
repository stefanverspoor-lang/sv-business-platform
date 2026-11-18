<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['type', 'name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function options(string $type): array
    {
        return static::query()->where('type', $type)->where('is_active', true)->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all();
    }
}
