<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Http\Middleware\ActivateCompanyDatabase;
use App\Http\Middleware\ApplyUserPreferences;
use App\Models\Company;
use App\Support\SvAvatarProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class)
            ->brandName('SV Business Platform')
            ->font('Figtree')
            ->colors([
                // SV-groen (#1F4D45 = 600), dezelfde huisstijl als de website
                'primary' => [
                    50 => 'oklch(0.975 0.012 178)',
                    100 => 'oklch(0.95 0.022 178)',
                    200 => 'oklch(0.88 0.04 178)',
                    300 => 'oklch(0.76 0.06 178)',
                    400 => 'oklch(0.6 0.07 178)',
                    500 => 'oklch(0.47 0.065 178)',
                    600 => 'oklch(0.385 0.055 178)',
                    700 => 'oklch(0.33 0.048 178)',
                    800 => 'oklch(0.28 0.04 178)',
                    900 => 'oklch(0.23 0.033 178)',
                    950 => 'oklch(0.17 0.025 178)',
                ],
                'gray' => Color::Stone,
            ])
            ->defaultAvatarProvider(SvAvatarProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make(fn () => __('CRM')),
                NavigationGroup::make(fn () => __('Telemarketing')),
                NavigationGroup::make(fn () => __('Pre-incasso')),
                NavigationGroup::make(fn () => __('Websites')),
                NavigationGroup::make(fn () => __('Data-entry')),
                NavigationGroup::make(fn () => __('Facturatie')),
                NavigationGroup::make(fn () => __('Financiën')),
                NavigationGroup::make(fn () => __('Beheer'))->collapsed(),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            // Bedrijven: SV Business Support en YES Marketing Solutions
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantProfile(EditCompanyProfile::class)
            ->tenantMiddleware([ActivateCompanyDatabase::class], isPersistent: true)
            // Tweestapsverificatie is verplicht voor iedereen
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->pages([Dashboard::class])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                // Geeft de tijdzone van de computer door aan het CRM.
                fn (): string => Blade::render(<<<'HTML'
                    <script>
                        try {
                            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                            if (tz) document.cookie = 'crm_tz=' + encodeURIComponent(tz) + ';path=/;max-age=31536000;SameSite=Lax;Secure';
                        } catch (e) {}
                    </script>
                    HTML),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Taal en tijdzone ook bij acties op de achtergrond (Livewire).
            ->middleware([ApplyUserPreferences::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
