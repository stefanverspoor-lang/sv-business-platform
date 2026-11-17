<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Policies\UserPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\TenantManager;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
    }

    public function boot(): void
    {
        // Strenger gedrag tijdens ontwikkelen, zodat fouten vroeg opvallen.
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers()->uncompromised());

        // De superadmin mag alles, in beide bedrijven.
        Gate::before(fn (User $user) => $user->isSuperadmin() ? true : null);

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                Audit::log('login', $event->user, userId: $event->user->getKey());
            }
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof User) {
                Audit::log('logout', $event->user, userId: $event->user->getKey());
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            Audit::log('login_failed', null, ['email' => $event->credentials['email'] ?? null]);
        });
    }
}
