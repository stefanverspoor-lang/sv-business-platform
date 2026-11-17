<?php

namespace App\Filament\Resources\Tasks\Pages;

/**
 * Controleert taakgegevens uit een formulier: zonder tasks.manage kun je
 * alleen jezelf toewijzen.
 */
class TaskData
{
    public static function prepare(array $data, bool $creating): array
    {
        $user = auth()->user();

        if (! $user->can('tasks.manage')) {
            $data['assigned_to'] = $user->getKey();
        }
        if ($creating) {
            $data['created_by'] = $user->getKey();
        }
        if (array_key_exists('taskable_id', $data)) {
            $data['taskable_type'] = $data['taskable_id'] ? 'client' : null;
        }

        return $data;
    }
}
