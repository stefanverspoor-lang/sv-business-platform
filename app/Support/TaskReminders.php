<?php

namespace App\Support;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Stuurt een melding in het CRM als de herinneringstijd van een taak is bereikt.
 */
class TaskReminders
{
    public function send(Company $company): int
    {
        $sent = 0;

        $due = Task::query()->open()
            ->whereNotNull('remind_at')->where('remind_at', '<=', now())->whereNull('reminded_at')
            ->whereNotNull('assigned_to')->limit(100)->get();

        foreach ($due as $task) {
            $user = User::find($task->assigned_to);
            if ($user?->is_active) {
                Notification::make()
                    ->title(__('Herinnering: :title', ['title' => $task->title], $user->locale))
                    ->body($task->due_at ? __('Deadline: :date', ['date' => $task->due_at->timezone($user->timezone ?: config('crm.fallback_timezone'))->format('d-m-Y H:i')], $user->locale) : null)
                    ->icon('heroicon-o-bell-alert')
                    ->warning()
                    ->actions([
                        Action::make('open')->label(__('Openen', [], $user->locale))
                            ->url(TaskResource::getUrl('edit', ['record' => $task], tenant: $company)),
                    ])
                    ->sendToDatabase($user);
                $sent++;
            }
            $task->forceFill(['reminded_at' => now()])->saveQuietly();
        }

        return $sent;
    }
}
