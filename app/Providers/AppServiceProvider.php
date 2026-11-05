<?php

namespace App\Providers;

use App\Actions\Shifts\ReplaceShift;
use App\Activity\ActivityLogger;
use App\Activity\CoreActivityActions;
use App\Activity\DefaultActivityActionRegistry;
use App\Authorization\Authorizer;
use App\Bundles\BundleManager;
use App\Cron\RecordScheduledTaskRun;
use App\Dashboard\CoreWidgets;
use App\Dashboard\DefaultDashboardWidgetRegistry;
use App\Exports\CoreExportDatasets;
use App\Exports\DefaultExportDatasetRegistry;
use App\Http\Middleware\EnsureApiAccountIsActive;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\SetApiLocale;
use App\Listeners\RecordActivity;
use App\Listeners\RemovePushSubscriptionOnLogout;
use App\Listeners\SendShiftNotifications;
use App\Models\User;
use App\Navigation\CoreNavigation;
use App\Navigation\DefaultNavigationRegistry;
use App\Navigation\NavigationMenu;
use App\Notifications\CoreNotificationTypes;
use App\Notifications\DefaultNotificationTypeRegistry;
use App\Notifications\NotificationInbox;
use App\Notifications\UserNotifier;
use App\Planning\NullTimeOffChecker;
use App\Planning\NullTimeOffProvider;
use App\Profile\DefaultProfileSectionRegistry;
use App\Push\MinishlinkWebPushSender;
use App\Push\WebPushSender;
use App\Requests\DefaultPendingRequestRegistry;
use App\Settings\AppSettings;
use App\Settings\CoreSettings;
use App\Settings\RuntimeSettings;
use App\Stores\DefaultStoreFeatureRegistry;
use App\Stores\StoreColleagueDirectory;
use App\System\CoreSystemAlerts;
use App\System\SystemAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Activity\ActivityRecorder;
use Kintai\Contracts\Bundle\BundleActivation;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Events\EmployeeCreated;
use Kintai\Contracts\Events\EmployeePasswordReset;
use Kintai\Contracts\Events\EmployeeRatesUpdated;
use Kintai\Contracts\Events\EmployeeUpdated;
use Kintai\Contracts\Events\PasswordChanged;
use Kintai\Contracts\Events\ProfileUpdated;
use Kintai\Contracts\Events\RoleDeleted;
use Kintai\Contracts\Events\RoleHoldersChanged;
use Kintai\Contracts\Events\RoleSaved;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Events\ShiftRestored;
use Kintai\Contracts\Events\ShiftTypeCreated;
use Kintai\Contracts\Events\ShiftTypeDeleted;
use Kintai\Contracts\Events\ShiftTypeUpdated;
use Kintai\Contracts\Events\ShiftUpdated;
use Kintai\Contracts\Events\StoreCreated;
use Kintai\Contracts\Events\StoreDeleted;
use Kintai\Contracts\Events\StoreMemberAdded;
use Kintai\Contracts\Events\StoreMemberRemoved;
use Kintai\Contracts\Events\StoreMemberUpdated;
use Kintai\Contracts\Events\StoreUpdated;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Notifications\Notifier;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Planning\TimeOffProvider;
use Kintai\Contracts\Profile\ProfileSectionRegistry;
use Kintai\Contracts\Requests\PendingRequestRegistry;
use Kintai\Contracts\Settings\Settings;
use Kintai\Contracts\Shifts\ShiftReplacer;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Kintai\Contracts\Stores\StoreFeatureRegistry;
use Kintai\Contracts\System\SystemAlertRegistry;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Points d'extension du contrat `kintai/contracts` : le Core lie chaque interface à son registre en mémoire (un par processus).
        $this->app->singleton(ProfileSectionRegistry::class, DefaultProfileSectionRegistry::class);
        $this->app->singleton(StoreFeatureRegistry::class, DefaultStoreFeatureRegistry::class);
        $this->app->singleton(NavigationRegistry::class, DefaultNavigationRegistry::class);
        $this->app->singleton(PendingRequestRegistry::class, DefaultPendingRequestRegistry::class);
        $this->app->singleton(DashboardWidgetRegistry::class, DefaultDashboardWidgetRegistry::class);
        $this->app->singleton(NotificationTypeRegistry::class, DefaultNotificationTypeRegistry::class);
        $this->app->singleton(ActivityActionRegistry::class, DefaultActivityActionRegistry::class);
        $this->app->singleton(ExportDatasetRegistry::class, DefaultExportDatasetRegistry::class);
        $this->app->bind(ActivityRecorder::class, ActivityLogger::class);
        $this->app->bind(ShiftReplacer::class, ReplaceShift::class);
        $this->app->bind(ColleagueDirectory::class, StoreColleagueDirectory::class);
        $this->app->bind(Notifier::class, UserNotifier::class);
        // Réglages de l'instance : le Core déclare les siens ici, les bundles et les autres tranches y ajoutent les leurs.
        $this->app->singleton(AppSettings::class, function (): AppSettings {
            $settings = new AppSettings;
            CoreSettings::registerIn($settings);

            return $settings;
        });
        $this->app->alias(AppSettings::class, Settings::class);
        // Bundles : le gestionnaire sait lesquels sont installés et activés ; les bundles le consultent par l'interface du contrat.
        $this->app->singleton(BundleManager::class);
        $this->app->alias(BundleManager::class, BundleActivation::class);
        $this->app->singleton(RuntimeSettings::class);
        // Alertes système : le Core déclare ses détecteurs, les bundles et la phase des mises à jour y ajoutent les leurs.
        // Portée requête / tâche (comme l'Authorizer qu'elle utilise).
        $this->app->scoped(SystemAlerts::class, function ($app): SystemAlerts {
            $alerts = new SystemAlerts($app->make(AppSettings::class), $app->make(Authorizer::class), $app->make(NotificationInbox::class));
            CoreSystemAlerts::registerIn($alerts);

            return $alerts;
        });
        $this->app->alias(SystemAlerts::class, SystemAlertRegistry::class);
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
        $this->registerBundleApiMiddleware();
        $this->registerProfileSections();
        $this->registerStoreFeatures();
        $this->registerNavigation();
        $this->registerDashboardWidgets();
        $this->registerNotificationTypes();
        $this->registerShiftNotifications();
        Event::listen(Logout::class, RemovePushSubscriptionOnLogout::class);
        Event::listen(ScheduledTaskFinished::class, [RecordScheduledTaskRun::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [RecordScheduledTaskRun::class, 'failed']);
        $this->registerActivityLog();
        $this->registerExportDatasets();
        $this->registerRateLimiters();
        // En dernier : les réglages de l'Owner (langue, fuseau, expéditeur, URL publique) remplacent la configuration.
        $this->app->make(RuntimeSettings::class)->apply();
    }

    /**
     * Pile de l'API des bundles (`routes/api.php` d'un bundle) : la même que l'API du Core (langue, maintenance,
     * limiteur, jeton Sanctum, compte actif).
     */
    private function registerBundleApiMiddleware(): void
    {
        $this->app->make('router')->middlewareGroup(BundleServiceProvider::API_MIDDLEWARE, [
            SetApiLocale::class,
            MaintenanceMode::class,
            'throttle:api',
            'auth:sanctum',
            EnsureApiAccountIsActive::class,
        ]);
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
     * API v1 : requêtes par minute et par jeton, ou par adresse IP sans jeton (réglage `api.rate_limit`, 300 par défaut ;
     * 0 = sans limite).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->user('sanctum')?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'ip:'.$request->ip();

            $perMinute = app(AppSettings::class)->apiRateLimit();

            return $perMinute === 0 ? Limit::none() : Limit::perMinute($perMinute)->by($key);
        });

        // Appel HTTP des tâches planifiées : un service en ligne appelle chaque minute ; 30 par minute et par adresse IP laissent la marge
        // d'une relance, et freinent la recherche d'un jeton.
        RateLimiter::for('cron', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));

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
        $sections->register('availability', 'Availability', 'availability.edit', 15, fn (Authenticatable $user): bool => $user instanceof User && $user->stores()->exists());
        $sections->register('notifications', 'Notifications', 'notification-preferences.edit', 22);
        $sections->register('calendar', 'Calendar', 'calendar.edit', 23, fn (Authenticatable $user): bool => $user instanceof User && $user->stores()->exists());
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
