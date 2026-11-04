<?php

namespace App\Providers;

use App\Activity\ActivityActionRegistry;
use App\Activity\CoreActivityActions;
use App\Contracts\Planning\TimeOffChecker;
use App\Contracts\Planning\TimeOffProvider;
use App\Dashboard\CoreWidgets;
use App\Dashboard\DashboardWidgetRegistry;
use App\Events\EmployeeCreated;
use App\Events\EmployeePasswordReset;
use App\Events\EmployeeRatesUpdated;
use App\Events\EmployeeUpdated;
use App\Events\PasswordChanged;
use App\Events\ProfileUpdated;
use App\Events\RoleDeleted;
use App\Events\RoleHoldersChanged;
use App\Events\RoleSaved;
use App\Events\ShiftCreated;
use App\Events\ShiftDeleted;
use App\Events\ShiftReplaced;
use App\Events\ShiftRestored;
use App\Events\ShiftTypeCreated;
use App\Events\ShiftTypeDeleted;
use App\Events\ShiftTypeUpdated;
use App\Events\ShiftUpdated;
use App\Events\StoreCreated;
use App\Events\StoreDeleted;
use App\Events\StoreMemberAdded;
use App\Events\StoreMemberRemoved;
use App\Events\StoreMemberUpdated;
use App\Events\StoreUpdated;
use App\Events\UserAnonymized;
use App\Exports\CoreExportDatasets;
use App\Exports\ExportDatasetRegistry;
use App\Listeners\RecordActivity;
use App\Listeners\RemovePushSubscriptionOnLogout;
use App\Listeners\SendShiftNotifications;
use App\Models\User;
use App\Navigation\CoreNavigation;
use App\Navigation\NavigationMenu;
use App\Navigation\NavigationRegistry;
use App\Notifications\CoreNotificationTypes;
use App\Notifications\NotificationTypeRegistry;
use App\Planning\NullTimeOffChecker;
use App\Planning\NullTimeOffProvider;
use App\Profile\ProfileSectionRegistry;
use App\Push\MinishlinkWebPushSender;
use App\Push\WebPushSender;
use App\Requests\PendingRequestRegistry;
use App\Stores\StoreFeatureRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
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
        $this->app->singleton(NotificationTypeRegistry::class);
        $this->app->singleton(ActivityActionRegistry::class);
        $this->app->singleton(ExportDatasetRegistry::class);
        $this->app->bind(WebPushSender::class, MinishlinkWebPushSender::class);
        $this->app->scoped(NavigationMenu::class);
        // Sans bundle de congés, personne n'est en congé ; le bundle timeoff remplace cette liaison.
        $this->app->bind(TimeOffChecker::class, NullTimeOffChecker::class);
        // Idem pour les congés publiés dans le flux iCal.
        $this->app->bind(TimeOffProvider::class, NullTimeOffProvider::class);
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
        $this->registerNotificationTypes();
        $this->registerShiftNotifications();
        Event::listen(Logout::class, RemovePushSubscriptionOnLogout::class);
        $this->registerActivityLog();
        $this->registerExportDatasets();
        $this->registerRateLimiters();
    }

    /**
     * Jeux de données exportables du Core ; les bundles y ajouteront les leurs.
     */
    private function registerExportDatasets(): void
    {
        CoreExportDatasets::registerIn($this->app->make(ExportDatasetRegistry::class), $this->app);
    }

    /**
     * Flux iCal public : 60 lectures par minute et par adresse IP (un agenda relit environ une fois par heure).
     * API v1 : 300 requêtes par minute et par jeton, ou par adresse IP sans jeton (`kintai.api.rate_limit`).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->user('sanctum')?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('kintai.api.rate_limit', 300))->by($key);
        });

        RateLimiter::for('ical', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
    }

    /**
     * Types de notification du Core ; les bundles y ajouteront les leurs.
     */
    private function registerNotificationTypes(): void
    {
        CoreNotificationTypes::registerIn($this->app->make(NotificationTypeRegistry::class));
    }

    /**
     * Libellés des actions du Core ; les bundles y ajouteront les leurs.
     */
    private function registerActivityLog(): void
    {
        CoreActivityActions::registerIn($this->app->make(ActivityActionRegistry::class));

        $listeners = [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'loginFailed',
            PasswordReset::class => 'passwordReset',
            PasswordChanged::class => 'passwordChanged',
            ProfileUpdated::class => 'profileUpdated',
            EmployeeCreated::class => 'employeeCreated',
            EmployeeUpdated::class => 'employeeUpdated',
            EmployeePasswordReset::class => 'employeePasswordReset',
            EmployeeRatesUpdated::class => 'employeeRatesUpdated',
            UserAnonymized::class => 'userAnonymized',
            StoreCreated::class => 'storeCreated',
            StoreUpdated::class => 'storeUpdated',
            StoreDeleted::class => 'storeDeleted',
            StoreMemberAdded::class => 'storeMemberAdded',
            StoreMemberUpdated::class => 'storeMemberUpdated',
            StoreMemberRemoved::class => 'storeMemberRemoved',
            ShiftTypeCreated::class => 'shiftTypeCreated',
            ShiftTypeUpdated::class => 'shiftTypeUpdated',
            ShiftTypeDeleted::class => 'shiftTypeDeleted',
            ShiftCreated::class => 'shiftCreated',
            ShiftUpdated::class => 'shiftUpdated',
            ShiftDeleted::class => 'shiftDeleted',
            ShiftRestored::class => 'shiftRestored',
            ShiftReplaced::class => 'shiftReplaced',
            RoleSaved::class => 'roleSaved',
            RoleDeleted::class => 'roleDeleted',
            RoleHoldersChanged::class => 'roleHoldersChanged',
        ];

        foreach ($listeners as $event => $method) {
            Event::listen($event, [RecordActivity::class, $method]);
        }
    }

    /**
     * L'employé est prévenu quand son planning change, quelle que soit l'origine de l'écriture.
     */
    private function registerShiftNotifications(): void
    {
        Event::listen(ShiftCreated::class, [SendShiftNotifications::class, 'created']);
        Event::listen(ShiftUpdated::class, [SendShiftNotifications::class, 'updated']);
        Event::listen(ShiftDeleted::class, [SendShiftNotifications::class, 'deleted']);
        Event::listen(ShiftRestored::class, [SendShiftNotifications::class, 'restored']);
        Event::listen(ShiftReplaced::class, [SendShiftNotifications::class, 'replaced']);
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
        $sections->register('notifications', 'Notifications', 'notification-preferences.edit', 22);
        $sections->register('calendar', 'Calendar', 'calendar.edit', 23, fn (User $user): bool => $user->stores()->exists());
        $sections->register('data', 'My data', 'data.edit', 35);
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
