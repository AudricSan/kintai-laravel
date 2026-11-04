<?php

namespace App\Providers;

use App\Profile\ProfileSectionRegistry;
use App\Stores\StoreFeatureRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProfileSectionRegistry::class);
        $this->app->singleton(StoreFeatureRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerProfileSections();
        $this->registerStoreFeatures();
    }

    /**
     * Fonctionnalités du corps activables par magasin ; les bundles y ajouteront les leurs.
     */
    private function registerStoreFeatures(): void
    {
        $this->app->make(StoreFeatureRegistry::class)->register('shifts', 'Planning');
    }

    /**
     * Pages du corps dans la zone « réglages personnels » ; les bundles y ajouteront les leurs.
     */
    private function registerProfileSections(): void
    {
        $sections = $this->app->make(ProfileSectionRegistry::class);

        $sections->register('profile', 'Profile', 'profile.edit', 10);
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
