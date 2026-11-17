<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Audit\Audit;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        $role = $this->form->getRawState()['company_role'] ?? null;
        $old = $record->roles->pluck('name')->all();
        if ($role && $old !== [$role]) {
            $record->syncRoles([$role]);
            Audit::log('role_changed', $record, ['old' => $old, 'new' => $role, 'company' => Filament::getTenant()->slug]);
        }

        return $record;
    }
}
