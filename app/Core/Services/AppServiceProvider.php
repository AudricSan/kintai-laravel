<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\ServiceProvider;
use kintai\Core\Container;
use kintai\Core\Cron\BackupJob;
use kintai\Core\Cron\CronRunner;
use kintai\Core\Cron\LicenseCheckJob;
use kintai\Core\Cron\LogPurgeJob;
use kintai\Core\Cron\CalendarSyncJob;
use kintai\Core\Cron\NotificationPurgeJob;
use kintai\Core\Services\Calendar\CurlHttpTransport;
use kintai\Core\Services\Calendar\HttpTransportInterface;
use kintai\Core\Database\BundleMigrationRunner;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\CronTokenRepositoryInterface;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Repositories\NotificationPreferenceRepositoryInterface;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use kintai\Core\Repositories\PasswordResetRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Repositories\InstalledBundleRepositoryInterface;
use kintai\Core\Services\BundleInstaller\BundleInstallerService;
use kintai\Core\Services\BundleRegistry\BundleRegistryClient;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\UI\ViewRenderer;

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
        $this->container->singleton(MailerService::class, function (Container $c) {
            $path = dirname(dirname(dirname(__DIR__))) . '/config/mail.php';
            $config = file_exists($path) ? require $path : [];
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
        $this->container->singleton(PushNotificationService::class, function (Container $c) {
            $path = dirname(dirname(dirname(__DIR__))) . '/config/push.php';
            $config = file_exists($path) ? require $path : [];
            return new PushNotificationService($config, $c->make(DevicePushTokenRepositoryInterface::class));
        });
    }

    private function registerLogging(): void
    {
        $this->container->singleton(AuditLogger::class, fn() => new AuditLogger());

        Log::setContainer($this->container);
    }

    private function registerBusinessServices(): void
    {
        $this->container->singleton(NotificationService::class, fn(Container $c) => new NotificationService(
            $c->make(NotificationRepositoryInterface::class),
            $c->make(PushNotificationService::class),
            $c->make(TranslationService::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(NotificationTypeRegistry::class),
            $c->make(NotificationPreferenceService::class),
            $c->make(WebPushService::class),
            $c->make(NotificationMailer::class),
        ));
        $this->container->singleton(NotificationChannelStatus::class, function (Container $c) {
            $path = dirname(dirname(dirname(__DIR__))) . '/config/push.php';

            return new NotificationChannelStatus(
                $c->make(VapidKeyStore::class),
                $c->make(WebPushSubscriptionRepositoryInterface::class),
                $c->make(DevicePushTokenRepositoryInterface::class),
                $c->make(MailerService::class),
                $c->make(PublicUrlResolver::class),
                $c->make(UserRepositoryInterface::class),
                file_exists($path) ? require $path : [],
            );
        });
        $this->container->singleton(HttpTransportInterface::class, fn() => new CurlHttpTransport());
        $this->container->singleton(UnsubscribeTokens::class,fn(Container $c) => new UnsubscribeTokens($c->make(AppSettingsRepositoryInterface::class)));
        $this->container->singleton(NotificationMailer::class, fn(Container $c) => new NotificationMailer(
            $c->make(MailerService::class),
            $c->make(PublicUrlResolver::class),
            $c->make(UnsubscribeTokens::class),
            $c->make(TranslationService::class),
        ));
        $this->container->singleton(VapidKeyStore::class, fn(Container $c) => new VapidKeyStore($c->make(AppSettingsRepositoryInterface::class)));
        $this->container->singleton(WebPushService::class, fn(Container $c) => new WebPushService(
            $c->make(WebPushSubscriptionRepositoryInterface::class),
            $c->make(VapidKeyStore::class),
            null,
            $c->make(WebPushEndpointValidator::class),
        ));
        $this->container->singleton(NotificationPreferenceService::class, fn(Container $c) => new NotificationPreferenceService(
            $c->make(NotificationPreferenceRepositoryInterface::class),
            $c->make(NotificationTypeRegistry::class),
            $c->make(UserRepositoryInterface::class),
        ));
        
        $this->container->singleton(IcalService::class, fn(Container $c) => new IcalService(
            $c->make(TranslationService::class),
        ));
        
        $this->container->singleton(PasswordResetService::class, fn(Container $c) => new PasswordResetService(
            $c->make(PasswordResetRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(MailerService::class),
            $c->make(\kintai\Core\Auth\CredentialRevoker::class),
            $c->make(TranslationService::class),
        ));

        $this->container->singleton(AppSettingsService::class, fn(Container $c) => new AppSettingsService($c->make(AppSettingsRepositoryInterface::class)));

        $this->container->singleton(PublicUrlResolver::class, fn(Container $c) => new PublicUrlResolver($c->make(AppSettingsService::class)));

        $this->container->singleton(MascotResolver::class, fn(Container $c) => new MascotResolver($c->make(AppSettingsService::class)));

        $this->container->singleton(ShiftServiceInterface::class, fn(Container $c) => new ShiftService(
            $c->make(ShiftRepositoryInterface::class),
            $c->make(ShiftTypeRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
        ));

        $this->container->singleton(LicenseTokenVerifier::class, function (Container $c) {
            $path = dirname(dirname(dirname(__DIR__))) . '/config/license_server.php';
            $config = file_exists($path) ? require $path : [];
            return new LicenseTokenVerifier($config['public_key_pem'] ?? null);
        });

        $this->container->singleton(LicenseClientService::class, function (Container $c) {
            $path = dirname(dirname(dirname(__DIR__))) . '/config/license_server.php';
            $config = file_exists($path) ? require $path : [];
            return new LicenseClientService(
                $c->make(AppSettingsRepositoryInterface::class),
                $config,
                tokenVerifier: $c->make(LicenseTokenVerifier::class),
            );
        });

        $this->container->singleton(PlanLimitService::class, fn(Container $c) => new PlanLimitService(
            $c->make(StoreRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(LicenseClientService::class),
        ));

        // Points d'extension des bundles (navigation…) : une instance par requête, qui garde ses résultats en cache.
        $this->container->singleton(\kintai\Core\Extension\ExtensionRegistry::class, fn(Container $c) => new \kintai\Core\Extension\ExtensionRegistry(
            $c->make(\kintai\Core\BundleManager::class),
        ));
        // Ventes journalières (contrat 1.14), aussi exposées aux bundles (kintai\Contract\Services\Sales).
        $this->container->bind(\kintai\Core\Extension\Sales::class, fn() => \kintai\Core\Extension\Sales::current());

        $this->container->singleton(StoreServiceInterface::class, fn(Container $c) => new StoreService(
            $c->make(StoreRepositoryInterface::class),
            $c->make(StoreUserRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(LanguageRepositoryInterface::class),
            $c->make(PlanLimitService::class),
            $c->make(\kintai\Core\Routing\RouteSlugService::class),
            $c->make(\kintai\Core\Repositories\RoleAssignmentRepositoryInterface::class),
        ));

        $this->container->singleton(StoreStatsServiceInterface::class, fn(Container $c) => new StoreStatsService(
            $c->make(StoreRepositoryInterface::class),
            $c->make(ShiftRepositoryInterface::class),
            $c->make(ShiftTypeRepositoryInterface::class),
            $c->make(StoreUserRepositoryInterface::class),
            $c->make(UserShiftTypeRateRepositoryInterface::class),
            $c->make(UserRepositoryInterface::class),
            $c->make(RoleAssignmentSyncService::class),
            $c->make(\kintai\Core\Extension\Sales::class),
        ));

        $this->container->singleton(BackupService::class, fn(Container $c) => new BackupService(
            $c->make(Capsule::class),
        ));

        $this->container->singleton(AppResetService::class, fn(Container $c) => new AppResetService(
            $c->make(Capsule::class),
            $c->make(MigrationRunner::class),
        ));

        $this->container->singleton(UpdateService::class, fn() => new UpdateService());

        $this->container->singleton(GithubUpdateService::class, fn(Container $c) => new GithubUpdateService(
            $c->make(UpdateService::class),
            $c->make(BackupService::class),
            $c->make(MigrationRunner::class),
            $c->make(AppSettingsService::class),
        ));

        // Binding explicite requis (même raison que GithubUpdateService juste au-dessus) :
        // le constructeur a un paramètre ?\Closure.
        $this->container->singleton(GithubIssueService::class, fn() => new GithubIssueService());

        // Même raison (paramètre ?\Closure) : BundleRegistryClient et BundleInstallerService.
        $this->container->singleton(BundleRegistryClient::class, fn() => new BundleRegistryClient());

        // Questions de l'assistant qui recommandent des bundles (config/setup-questions.php).
        $this->container->singleton(\kintai\Core\Setup\SetupQuestionnaire::class, fn() => \kintai\Core\Setup\SetupQuestionnaire::fromConfig(BASE_PATH . '/config/setup-questions.php'));

        // Assistant de configuration : bundles du catalogue pour un canal, installés comme depuis /admin/bundles/market.
        $this->container->singleton(SetupWizard::class, fn(Container $c) => new SetupWizard(
            $c->make(\kintai\Core\Repositories\AppSettingsRepositoryInterface::class),
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

        $this->container->singleton(BundleInstallerService::class, fn(Container $c) => new BundleInstallerService(
            $c->make(UpdateService::class),
            $c->make(InstalledBundleRepositoryInterface::class),
            migrationRunner: $c->make(BundleMigrationRunner::class),
            permissionGranter: $c->make(BundleInstaller\BundlePermissionGranter::class),
        ));

        // Binding explicite requis : le constructeur a un paramètre ?\Closure
        // (non "builtin" pour Container::resolveParameter), donc la résolution
        // par réflexion échouerait sinon en essayant d'instancier \Closure.
        $this->container->singleton(WikiSyncService::class, fn() => new WikiSyncService());

        $this->container->singleton(TranslationManagementService::class, fn(Container $c) => new TranslationManagementService(
            $c->make(TranslationRepositoryInterface::class),
        ));

        // Détecteurs des notifications système (Owners) ; le service lui-même s'auto-résout.
        $this->container->singleton(SystemAlertSourceInterface::class, fn(Container $c) => $c->make(SystemAlertDetectors::class));

        // Tâches du Core, puis celles des bundles actifs (Bundle::cronJobs(), contrat 1.8 : auto-validate de
        // daily-report, photo-retention de store-photos…).
        $this->container->singleton(CronRunner::class, function (Container $c) {
            $runner = new CronRunner($c->make(CronTokenRepositoryInterface::class));
            $runner->register($c->make(BackupJob::class));
            $runner->register($c->make(LogPurgeJob::class));
            $runner->register($c->make(NotificationPurgeJob::class));
            $runner->register($c->make(CalendarSyncJob::class));
            $runner->register($c->make(\kintai\Core\Cron\SystemNotificationsJob::class));
            $runner->register($c->make(LicenseCheckJob::class));
            if ($c->has(\kintai\Core\Extension\ExtensionRegistry::class)) {
                $runner->registerBundleJobs(
                    $c->make(\kintai\Core\Extension\ExtensionRegistry::class)->cronJobsByBundle(),
                    fn(string $class): object => $c->make($class),
                );
            }
            return $runner;
        });
    }
}
