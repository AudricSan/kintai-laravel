<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Console\KintaiScriptCommand;
use App\BundleManager;
use App\Routing\EmployeeRouteBinder;
use App\Routing\RouteSegments;
use App\Routing\StoreRouteBinder;
use App\Foundation\Auth\KintaiGate;

/**
 * Raccorde Laravel aux services de Kintai pendant la migration : les helpers de Laravel (__(), csrf_token(),
 * csrf_field(), session(), auth()) et le Gate rendent ce que rendaient les services de Kintai ; les commandes Artisan
 * de Kintai (kintai:backup, kintai:cron-token…) sont déclarées ici.
 */
final class KintaiServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const OPTIONAL_SERVICES = [
        \App\Auth\CredentialRevoker::class,
        \App\Auth\OwnerGuard::class,
        \App\Auth\UserAnonymizer::class,
        \App\Extension\Attendances::class,
        \App\Extension\Events::class,
        \App\Extension\PendingRequests::class,
        \App\InstalledBundleManifestStore::class,
        \App\Routing\RouteSlugService::class,
        \App\Services\BundleActivationService::class,
        \App\Services\BundleInstaller\BundlePermissionGranter::class,
        \App\Services\CalendarSyncService::class,
        \App\Services\Calendar\GoogleCalendarSettings::class,
        \App\Services\DashboardLayoutResolver::class,
        \App\Services\EnvFileWriter::class,
        \App\Services\HttpFetcher::class,
        \App\Services\InstanceLocale::class,
        \App\Services\NotificationTypeRegistry::class,
        \App\Services\WebPushEndpointValidator::class,
    ];

    public function register(): void
    {
        defined('BASE_PATH') || define('BASE_PATH', rtrim($this->app->basePath(), '/\\'));

        // Noms du contrat de bundle (kintai\Contract\…) : alias du conteneur vers la classe réelle, qui porte la liaison.
        foreach (require __DIR__ . '/../Contract/surface.php' as $alias => $entry) {
            $this->app->alias($entry['target'], $alias);
        }

        // Services construits par le conteneur que des constructeurs reçoivent en paramètre facultatif (= null) : Laravel
        // laisse le défaut d'un paramètre dont la classe n'est pas liée, d'où ces liaisons explicites.
        foreach (self::OPTIONAL_SERVICES as $service) {
            $this->app->bind($service);
        }

        // Paramètres de route {store} et {employee} : segments lisibles ↔ identifiants (voir RouteSegments).
        $this->app->singleton(StoreRouteBinder::class);
        $this->app->singleton(EmployeeRouteBinder::class);

        $this->app->singleton(BundleManager::class);

    }

    public function boot(): void
    {
        RouteSegments::register($this->app['router']);
        \App\Http\Macros::register();

        // Racine de l'application (sous-dossier compris), pour les liens des vues.
        \Illuminate\Support\Facades\View::share('BASE_URL', base_url());

        if (config('session.driver') === 'file') {
            \Illuminate\Support\Facades\File::ensureDirectoryExists((string) config('session.files'));
        }


        // Le Gate de Laravel répond avec les permissions RBAC de Kintai.
        Gate::before(KintaiGate::before(...));

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\BackupCommand::class,
                \App\Console\Commands\LogPurgeCommand::class,
                \App\Console\Commands\LicenseCheckCommand::class,
                \App\Console\Commands\NotificationPurgeCommand::class,
                \App\Console\Commands\SystemNotificationsCommand::class,
                \App\Console\Commands\CalendarSyncCommand::class,
                \App\Console\Commands\CronTokenCommand::class,
                \App\Console\Commands\ContractCommand::class,
                \App\Console\Commands\CheckWikiCommand::class,
                \App\Console\Commands\CheckTranslationsCommand::class,
                \App\Console\Commands\NewBundleCommand::class,
            ]);
        }
    }
}
