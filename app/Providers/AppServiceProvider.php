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
use Illuminate\Database\Eloquent\Relations\Relation;
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
        Gate::policy(\App\Models\Task::class, \App\Policies\TaskPolicy::class);
        Gate::policy(\App\Models\Document::class, \App\Policies\DocumentPolicy::class);
        Gate::policy(\App\Models\Contract::class, \App\Policies\ContractPolicy::class);
        Gate::policy(\App\Models\EmailMessage::class, \App\Policies\EmailMessagePolicy::class);
        Gate::policy(\App\Models\Mailbox::class, \App\Policies\MailboxPolicy::class);
        Gate::policy(\App\Models\EmailTemplate::class, \App\Policies\EmailTemplatePolicy::class);
        Gate::policy(\App\Models\Campaign::class, \App\Policies\CampaignPolicy::class);
        Gate::policy(\App\Models\Lead::class, \App\Policies\LeadPolicy::class);
        Gate::policy(\App\Models\CollectionFile::class, \App\Policies\CollectionFilePolicy::class);
        Gate::policy(\App\Models\CommissionModel::class, \App\Policies\CommissionModelPolicy::class);
        Gate::policy(\App\Models\CallOutcome::class, \App\Policies\CallOutcomePolicy::class);
        Gate::policy(\App\Models\DataImport::class, \App\Policies\DataImportPolicy::class);
        Gate::policy(\App\Models\Project::class, \App\Policies\ProjectPolicy::class);
        Gate::policy(\App\Models\DataJob::class, \App\Policies\DataJobPolicy::class);
        Gate::policy(\App\Models\TimeEntry::class, \App\Policies\TimeEntryPolicy::class);

        // Vaste korte namen in de database voor gekoppelde onderdelen,
        // zodat ze niet afhangen van klassenamen in de code.
        Relation::enforceMorphMap([
            'user' => User::class,
            'client' => Client::class,
            'contact' => \App\Models\Contact::class,
            'contract' => \App\Models\Contract::class,
            'task' => \App\Models\Task::class,
            'document' => \App\Models\Document::class,
            'email_message' => \App\Models\EmailMessage::class,
            'access_grant' => \App\Models\AccessGrant::class,
            'campaign' => \App\Models\Campaign::class,
            'lead' => \App\Models\Lead::class,
            'collection_file' => \App\Models\CollectionFile::class,
            'payment_agreement' => \App\Models\PaymentAgreement::class,
            'commission_model' => \App\Models\CommissionModel::class,
            'call_outcome' => \App\Models\CallOutcome::class,
            'project' => \App\Models\Project::class,
            'project_request' => \App\Models\ProjectRequest::class,
            'data_job' => \App\Models\DataJob::class,
        ]);

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
