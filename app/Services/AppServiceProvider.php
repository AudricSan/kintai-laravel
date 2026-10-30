<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\ServiceProvider;
use Illuminate\Container\Container;
use App\Services\Calendar\CurlHttpTransport;
use App\Services\Calendar\HttpTransportInterface;
use App\Mail\MailerService;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\ShiftRepositoryInterface;
use App\Repositories\ShiftTypeRepositoryInterface;
use App\Repositories\NotificationRepositoryInterface;
use App\Models\WebPushSubscription;
use App\Repositories\UserShiftTypeRateRepositoryInterface;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\TranslationRepositoryInterface;
use App\Repositories\DevicePushTokenRepositoryInterface;
use App\Services\BundleInstaller\BundleInstallerService;
use App\Services\BundleRegistry\BundleRegistryClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Contracts\View\Factory as ViewFactory;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerMail();
        $this->registerPush();
        $this->registerLogging();
        $this->registerBusinessServices();
    }

    private function registerMail(): void
    {
        $this->app->singleton(MailerService::class, function (Container $c) {
            $config = (array) config('mail');
            // L'expéditeur réglé par l'Owner l'emporte sur config/mail.php. Base pas encore prête (installation) :
            // la configuration du fichier s'applique telle quelle.
            try {
                $config = $c->make(AppSettingsService::class)->mailConfig($config);
            } catch (\Throwable) {
            }
            return new MailerService($config);
        });
    }

    private function registerPush(): void
    {
        $this->app->singleton(PushNotificationService::class, function (Container $c) {
            $path = dirname(dirname(__DIR__)) . '/config/push.php';
            $config = file_exists($path) ? require $path : [];
            return new PushNotificationService($config, $c->make(DevicePushTokenRepositoryInterface::class));
        });
    }

    private function registerLogging(): void
    {
        $this->app->singleton(AuditLogger::class, fn() => new AuditLogger());
    }

    /**
     * File d'attente des canaux externes des notifications (config/queue.php, QUEUE_CONNECTION). null quand la
     * configuration de Laravel n'est pas chargée (scripts, socle des tests) ou vaut « sync » : envoi immédiat. La file
     * « deferred » (par défaut) envoie une fois la réponse HTTP partie : en ligne de commande, rien ne la viderait.
     */
    private static function notificationQueue(Container $c): ?Dispatcher
    {
        try {
            $default = $c->make('config')->get('queue.default');
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($default) || $default === '' || $default === 'sync' || ($default === 'deferred' && PHP_SAPI === 'cli')) {
            return null;
        }

        return $c->make(Dispatcher::class);
    }

    private function registerBusinessServices(): void
    {
        $this->app->singleton(NotificationService::class, fn(Container $c) => new NotificationService(
            $c->make(NotificationRepositoryInterface::class),
            $c->make(PushNotificationService::class),
            $c->make('translator'),
            $c->make(UserRepositoryInterface::class),
            $c->make(NotificationTypeRegistry::class),
            $c->make(NotificationPreferenceService::class),
            $c->make(WebPushService::class),
            $c->make(NotificationMailer::class),
            self::notificationQueue($c),
        ));
        $this->app->singleton(NotificationChannelStatus::class, function (Container $c) {
            $path = dirname(dirname(__DIR__)) . '/config/push.php';

            return new NotificationChannelStatus(
                $c->make(VapidKeyStore::class),
                $c->make(DevicePushTokenRepositoryInterface::class),
                $c->make(MailerService::class),
                $c->make(PublicUrlResolver::class),
                $c->make(UserRepositoryInterface::class),
                file_exists($path) ? require $path : [],
            );
        });
        $this->app->singleton(HttpTransportInterface::class, fn() => new CurlHttpTransport());
        $this->app->singleton(UnsubscribeTokens::class,fn(Container $c) => new UnsubscribeTokens($c->make(AppSettingsRepositoryInterface::class)));
        $this->app->singleton(NotificationMailer::class, fn(Container $c) => new NotificationMailer(
            $c->make(MailerService::class),
            $c->make(PublicUrlResolver::class),
            $c->make(UnsubscribeTokens::class),
            $c->make('translator'),
        ));
        $this->app->singleton(VapidKeyStore::class, fn(Container $c) => new VapidKeyStore($c->make(AppSettingsRepositoryInterface::class)));
        $this->app->singleton(WebPushService::class, fn(Container $c) => new WebPushService(
            $c->make(VapidKeyStore::class),
            null,
            $c->make(WebPushEndpointValidator::class),
        ));
        $this->app->singleton(NotificationPreferenceService::class, fn(Container $c) => new NotificationPreferenceService(
            $c->make(NotificationTypeRegistry::class),
            $c->make(UserRepositoryInterface::class),
        ));
        
        $this->app->singleton(IcalService::class, fn(Container $c) => new IcalService(
            $c->make('translator'),
        ));
        
        $this->app->singleton(PasswordResetService::class, fn(Container $c) => new PasswordResetService(
            $c->make(UserRepositoryInterface::class),
            $c->make(MailerService::class),
            $c->make(\App\Auth\CredentialRevoker::class),
            $c->make('translator'),
        ));

        $this->app->singleton(AppSettingsService::class, fn(Container $c) => new AppSettingsService($c->make(AppSettingsRepositoryInterface::class)));

        $this->app->singleton(PublicUrlResolver::class, fn(Container $c) => new PublicUrlResolver($c->make(AppSettingsService::class)));

        $this->app->singleton(MascotResolver::class, fn(Container $c) => new MascotResolver($c->make(AppSettingsService::class)));

        $this->app->singleton(ShiftServiceInterface::class, fn(Container $c) => new ShiftService(
            $c->make(ShiftRepositoryInterface::class),
            $c->make(ShiftTypeRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));

        // Partagé : les réglages de publication lus une fois servent à tous les filtres de la requête.
        $this->app->singleton(SchedulePublication::class, fn(Container $c) => new SchedulePublication(
            $c->make(StoreRepositoryInterface::class),
            $c->make(ShiftRepositoryInterface::class),
            $c->make(NotificationService::class),
        ));

        // Pointages lus à chaque appel (Attendances::current()) : les bundles actifs de la requête font foi.
        $this->app->bind(TimesheetService::class, fn(Container $c) => new TimesheetService(
            $c->make(ShiftRepositoryInterface::class),
        ));

        $this->app->singleton(LicenseTokenVerifier::class, function (Container $c) {
            $path = dirname(dirname(__DIR__)) . '/config/license_server.php';
            $config = file_exists($path) ? require $path : [];
            return new LicenseTokenVerifier($config['public_key_pem'] ?? null);
        });

        $this->app->singleton(LicenseClientService::class, function (Container $c) {
            $path = dirname(dirname(__DIR__)) . '/config/license_server.php';
            $config = file_exists($path) ? require $path : [];
            return new LicenseClientService(
                $c->make(AppSettingsRepositoryInterface::class),
                $config,
                tokenVerifier: $c->make(LicenseTokenVerifier::class),
            );
        });

        $this->app->singleton(PlanLimitService::class, fn(Container $c) => new PlanLimitService(
            $c->make(StoreRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(LicenseClientService::class),
        ));

        // Points d'extension des bundles (navigation…) : une instance par requête, qui garde ses résultats en cache.
        $this->app->singleton(\App\Extension\ExtensionRegistry::class, fn(Container $c) => new \App\Extension\ExtensionRegistry(
            $c->make(\App\BundleManager::class),
        ));
        // Ventes journalières (contrat 1.14), aussi exposées aux bundles (kintai\Contract\Services\Sales).
        $this->app->bind(\App\Extension\Sales::class, fn() => \App\Extension\Sales::current());

        $this->app->singleton(StoreServiceInterface::class, fn(Container $c) => new StoreService(
            $c->make(StoreRepositoryInterface::class),
            $c->make(StoreUserRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(LanguageRepositoryInterface::class),
            $c->make(PlanLimitService::class),
            $c->make(\App\Routing\RouteSlugService::class),
            $c->make(\App\Repositories\RoleAssignmentRepositoryInterface::class),
        ));

        $this->app->singleton(StoreStatsServiceInterface::class, fn(Container $c) => new StoreStatsService(
            $c->make(StoreRepositoryInterface::class),
            $c->make(ShiftRepositoryInterface::class),
            $c->make(ShiftTypeRepositoryInterface::class),
            $c->make(StoreUserRepositoryInterface::class),
            $c->make(UserShiftTypeRateRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(RoleAssignmentSyncService::class),
            $c->make(\App\Extension\Sales::class),
        ));

        $this->app->singleton(BackupService::class, fn(Container $c) => new BackupService(
            $c->make(Capsule::class),
        ));

        $this->app->singleton(AppResetService::class, fn(Container $c) => new AppResetService(
            $c->make(Capsule::class),
        ));

        $this->app->singleton(UpdateService::class, fn() => new UpdateService());

        $this->app->singleton(GithubUpdateService::class, fn(Container $c) => new GithubUpdateService(
            $c->make(UpdateService::class),
            $c->make(BackupService::class),
            $c->make(AppSettingsService::class),
        ));

        // Binding explicite requis (même raison que GithubUpdateService juste au-dessus) :
        // le constructeur a un paramètre ?\Closure.
        $this->app->singleton(GithubIssueService::class, fn() => new GithubIssueService());

        // Même raison (paramètre ?\Closure) : BundleRegistryClient et BundleInstallerService.
        $this->app->singleton(BundleRegistryClient::class, fn() => new BundleRegistryClient());

        // Questions de l'assistant qui recommandent des bundles (config/setup-questions.php).
        $this->app->singleton(\App\Setup\SetupQuestionnaire::class, fn() => \App\Setup\SetupQuestionnaire::fromConfig(BASE_PATH . '/config/setup-questions.php'));

        // Assistant de configuration : bundles du catalogue pour un canal, installés comme depuis /admin/bundles/market.
        $this->app->singleton(SetupWizard::class, fn(Container $c) => new SetupWizard(
            $c->make(\App\Repositories\AppSettingsRepositoryInterface::class),
            static function (string $channel) use ($c): array {
                $entries = [];
                foreach ($c->make(BundleRegistry\BundleCatalogService::class)->listAvailableBundles() as $entry) {
                    $version = $entry->bundle->versionsForChannel($channel)[0] ?? null;
                    if ($version === null || isset($entries[$entry->bundle->slug])) {
                        continue;
                    }
                    $entries[$entry->bundle->slug] = [
                        'slug' => $entry->bundle->slug, 'name' => $entry->bundle->name, 'description' => $entry->bundle->description,
                        'version' => $version, 'repository_url' => $entry->bundle->repositoryUrl, 'registry_url' => $entry->registryUrl,
                        'setup_question' => $entry->bundle->setupQuestion, 'requires' => $entry->bundle->requires,
                    ];
                }
                return array_values($entries);
            },
            static function (array $entry) use ($c): ?string {
                $pin = $c->make(BundleRegistry\BundleCatalogService::class)->resolvePin($entry['registry_url'], $entry['slug'], $entry['version']);
                if ($pin['error'] !== null) {
                    return $pin['error'];
                }
                $result = $c->make(BundleInstaller\BundleInstallerService::class)
                    ->install($entry['slug'], $entry['repository_url'], $entry['version'], $entry['registry_url'], null, $pin['commit']);
                return $result->success ? null : (string) $result->error;
            },
            $c->make(BundleActivationService::class),
            $c->make(PlanLimitService::class),
        ));

        $this->app->singleton(BundleInstallerService::class, fn(Container $c) => new BundleInstallerService(
            $c->make(UpdateService::class),
            migrator: $c->make('migrator'),
            permissionGranter: $c->make(BundleInstaller\BundlePermissionGranter::class),
        ));

        // Binding explicite requis : le constructeur a un paramètre ?\Closure
        // (non "builtin" pour Container::resolveParameter), donc la résolution
        // par réflexion échouerait sinon en essayant d'instancier \Closure.
        $this->app->singleton(WikiSyncService::class, fn() => new WikiSyncService());

        $this->app->singleton(TranslationManagementService::class, fn(Container $c) => new TranslationManagementService(
            $c->make(TranslationRepositoryInterface::class),
        ));

        // Détecteurs des notifications système (Owners) ; le service lui-même s'auto-résout.
        $this->app->singleton(SystemAlertSourceInterface::class, fn(Container $c) => $c->make(SystemAlertDetectors::class));
    }
}
