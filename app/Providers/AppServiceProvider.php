<?php

namespace App\Providers;

use App\Contracts\Planning\TimeOffChecker;
use App\Dashboard\CoreWidgets;
use App\Dashboard\DashboardWidgetRegistry;
use App\Models\User;
use App\Navigation\CoreNavigation;
use App\Navigation\NavigationMenu;
use App\Navigation\NavigationRegistry;
use App\Planning\NullTimeOffChecker;
use App\Profile\ProfileSectionRegistry;
use App\Requests\PendingRequestRegistry;
use App\Stores\StoreFeatureRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProfileSectionRegistry::class);
        $this->app->singleton(StoreFeatureRegistry::class);
        $this->app->singleton(NavigationRegistry::class);
        $this->app->singleton(PendingRequestRegistry::class);
        $this->app->singleton(DashboardWidgetRegistry::class);
        $this->app->scoped(NavigationMenu::class);
        // Sans bundle de congés, personne n'est en congé ; le bundle timeoff remplace cette liaison.
        $this->app->bind(TimeOffChecker::class, NullTimeOffChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerProfileSections();
        $this->registerStoreFeatures();
        $this->registerNavigation();
        $this->registerDashboardWidgets();
    }

    /**
     * Entrées de menu du Core ; les bundles y ajouteront les leurs.
     */
    private function registerNavigation(): void
    {
        CoreNavigation::registerIn(
            $this->app->make(NavigationRegistry::class),
            $this->app->make(PendingRequestRegistry::class),
        );
    }

    /**
     * Widgets du Core ; les bundles y ajouteront les leurs. Leurs composants Livewire vivent dans `resources/views/widgets`.
     */
    private function registerDashboardWidgets(): void
    {
        Livewire::addNamespace('widgets', viewPath: resource_path('views/widgets'));

        CoreWidgets::registerIn($this->app->make(DashboardWidgetRegistry::class));
    }

    /**
     * Fonctionnalités du Core activables par magasin ; les bundles y ajouteront les leurs.
     */
    private function registerStoreFeatures(): void
    {
        $this->app->make(StoreFeatureRegistry::class)->register('shifts', 'Planning');
    }

    /**
     * Pages du Core dans la zone « réglages personnels » ; les bundles y ajouteront les leurs.
     */
    private function registerProfileSections(): void
    {
        $sections = $this->app->make(ProfileSectionRegistry::class);

        $sections->register('profile', 'Profile', 'profile.edit', 10);
        $sections->register('availability', 'Availability', 'availability.edit', 15, fn (User $user): bool => $user->stores()->exists());
        $sections->register('navigation', 'Navigation', 'navigation.edit', 25);
        $sections->register('security', 'Security', 'security.edit', 20);
        $sections->register('appearance', 'Appearance', 'appearance.edit', 30);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Règle des employés de magasin : 8 caractères minimum, dans tous les environnements.
        Password::defaults(fn (): Password => Password::min(8));
    }
}
