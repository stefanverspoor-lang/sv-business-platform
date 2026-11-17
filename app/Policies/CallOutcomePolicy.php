<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CallOutcomePolicy extends AdminOnlyPolicy
{
    protected function permission(): string
    {
        return 'outcomes.manage';
    }

    /** Standaardresultaten uit de briefing kun je uitzetten, niet verwijderen. */
    public function delete(User $user, Model $model): bool
    {
        return ! $model->is_system && parent::delete($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
