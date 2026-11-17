<?php

namespace App\Models\Concerns;

use App\Support\Audit\Audit;

/**
 * Legt aanmaken, wijzigen, verwijderen en herstellen vast in de audit-log.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => Audit::log('created', $model, $model->getAttributes()));

        static::updated(function ($model) {
            $changes = [];
            foreach (array_keys($model->getChanges()) as $key) {
                if ($key === 'updated_at') {
                    continue;
                }
                $changes[$key] = ['old' => $model->getOriginal($key), 'new' => $model->getAttribute($key)];
            }
            if ($changes) {
                Audit::log('updated', $model, $changes);
            }
        });

        static::deleted(fn ($model) => Audit::log('deleted', $model));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => Audit::log('restored', $model));
        }
    }
}
