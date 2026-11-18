<?php

namespace App\Support;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\ProjectDomain;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Melding 30 dagen voordat een domeinnaam verloopt, aan de projectmedewerker
 * en de superadmins. Eén keer per verloopdatum.
 */
class DomainExpiryReminders
{
    public const DAYS_AHEAD = 30;

    public function send(Company $company): int
    {
        $sent = 0;
        $due = ProjectDomain::query()->with('project')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays(self::DAYS_AHEAD))
            ->whereDate('expires_at', '>=', now()->subDays(7))
            ->whereNull('expiry_notified_at')
            ->limit(50)->get();

        foreach ($due as $domain) {
            $project = $domain->project;
            if (! $project || $project->trashed()) {
                $domain->forceFill(['expiry_notified_at' => now()])->saveQuietly();

                continue;
            }

            $recipients = User::where('is_active', true)
                ->where(fn ($q) => $q->where('is_superadmin', true)->when($project->assigned_to, fn ($q2) => $q2->orWhere('id', $project->assigned_to)))
                ->get();

            foreach ($recipients as $user) {
                Notification::make()
                    ->title(__('Domein :domain verloopt op :date', ['domain' => $domain->domain, 'date' => $domain->expires_at->format('d-m-Y')], $user->locale))
                    ->body(($domain->auto_renew ? __('Staat op automatisch verlengen.', [], $user->locale) : __('Wordt niet automatisch verlengd.', [], $user->locale))
                        .' '.__('Project: :name', ['name' => $project->name], $user->locale))
                    ->icon('heroicon-o-globe-alt')
                    ->warning()
                    ->actions([
                        Action::make('open')->label(__('Openen', [], $user->locale))
                            ->url(ProjectResource::getUrl('view', ['record' => $project], tenant: $company)),
                    ])
                    ->sendToDatabase($user);
                $sent++;
            }
            $domain->forceFill(['expiry_notified_at' => now()])->saveQuietly();
        }

        return $sent;
    }
}
