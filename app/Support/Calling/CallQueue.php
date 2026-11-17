<?php

namespace App\Support\Calling;

use App\Enums\CampaignStatus;
use App\Models\CollectionFile;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bepaalt wie je als volgende belt. Een medewerker krijgt alleen eigen
 * toegewezen leads/dossiers; wie mag toewijzen krijgt ook niet-toegewezen.
 * Het gekozen record wordt vergrendeld zodat geen collega hetzelfde nummer belt.
 */
class CallQueue
{
    public const LEADS = 'leads';

    public const COLLECTION = 'collection';

    public function query(User $user, string $type, ?int $scopeId = null): Builder
    {
        if ($type === self::LEADS) {
            $query = Lead::query()
                ->whereHas('campaign', fn (Builder $q) => $q->where('status', CampaignStatus::Active))
                ->when($scopeId, fn (Builder $q) => $q->where('campaign_id', $scopeId));
            $canTakeUnassigned = $user->can('campaigns.manage');
        } else {
            $query = CollectionFile::query()->when($scopeId, fn (Builder $q) => $q->where('client_id', $scopeId));
            $canTakeUnassigned = $user->can('collection.manage');
        }

        return $query
            ->readyToCall($user)
            ->where(fn (Builder $q) => $canTakeUnassigned
                ? $q->where('assigned_to', $user->getKey())->orWhereNull('assigned_to')
                : $q->where('assigned_to', $user->getKey()));
    }

    public function count(User $user, string $type, ?int $scopeId = null): int
    {
        return $this->query($user, $type, $scopeId)->count();
    }

    /** @return Lead|CollectionFile|null */
    public function next(User $user, string $type, ?int $scopeId = null, array $skip = []): ?Model
    {
        $model = $type === self::LEADS ? new Lead : new CollectionFile;

        return DB::connection($model->getConnectionName())->transaction(function () use ($user, $type, $scopeId, $skip) {
            $record = $this->query($user, $type, $scopeId)
                ->when($skip, fn (Builder $q) => $q->whereNotIn('id', $skip))
                ->queueOrder()
                ->lockForUpdate()
                ->first();

            $record?->lockFor($user);

            return $record;
        });
    }
}
