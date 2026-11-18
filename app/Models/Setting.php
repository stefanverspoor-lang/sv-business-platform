<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;

/** Instellingen per bedrijf (in de eigen bedrijfsdatabase), bijv. voor facturatie. */
class Setting extends Model
{
    use BelongsToCompanyDatabase;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->find($key)?->value;

        return $value['v'] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }

    /** @return array<string, mixed> */
    public static function many(array $defaults): array
    {
        $stored = static::query()->whereIn('key', array_keys($defaults))->get()->mapWithKeys(fn ($s) => [$s->key => $s->value['v'] ?? null]);

        return collect($defaults)->map(fn ($default, $key) => $stored[$key] ?? $default)->all();
    }
}
