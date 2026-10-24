<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Core\InstalledBundleManifestStore;
use kintai\Core\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(UserRepositoryInterface::class, fn() => new DatabaseUserRepository());
        $this->container->singleton(StoreRepositoryInterface::class, fn() => new DatabaseStoreRepository());
        $this->container->singleton(StoreUserRepositoryInterface::class, fn() => new DatabaseStoreUserRepository());
        $this->container->singleton(RouteSlugRepositoryInterface::class, fn() => new DatabaseRouteSlugRepository());
        $this->container->singleton(ShiftTypeRepositoryInterface::class, fn() => new DatabaseShiftTypeRepository());
        $this->container->singleton(ShiftRepositoryInterface::class, fn() => new DatabaseShiftRepository());
        $this->container->singleton(AvailabilityRepositoryInterface::class, fn() => new DatabaseAvailabilityRepository());
        $this->container->singleton(UserShiftTypeRateRepositoryInterface::class, fn() => new DatabaseUserShiftTypeRateRepository());
        $this->container->singleton(IcalTokenRepositoryInterface::class, fn() => new DatabaseIcalTokenRepository());
        $this->container->singleton(UserDashboardPrefsRepositoryInterface::class, fn() => new DatabaseUserDashboardPrefsRepository());
        $this->container->singleton(UserDashboardLayoutRepositoryInterface::class, fn() => new DatabaseUserDashboardLayoutRepository());
        $this->container->singleton(RoleDashboardLayoutRepositoryInterface::class, fn() => new DatabaseRoleDashboardLayoutRepository());
        $this->container->singleton(UserNavPrefsRepositoryInterface::class, fn() => new DatabaseUserNavPrefsRepository());
        $this->container->singleton(NotificationRepositoryInterface::class, fn() => new DatabaseNotificationRepository());
        $this->container->singleton(NotificationPreferenceRepositoryInterface::class, fn() => new DatabaseNotificationPreferenceRepository());
        $this->container->singleton(WebPushSubscriptionRepositoryInterface::class, fn() => new DatabaseWebPushSubscriptionRepository());
        $this->container->singleton(CalendarSyncRepositoryInterface::class, fn() => new DatabaseCalendarSyncRepository());
        $this->container->singleton(ApiTokenRepositoryInterface::class, fn() => new DatabaseApiTokenRepository());
        $this->container->singleton(ImportAliasRepositoryInterface::class, fn() => new DatabaseImportAliasRepository());
        $this->container->singleton(PasswordResetRepositoryInterface::class, fn() => new DatabasePasswordResetRepository());
        $this->container->singleton(RememberTokenRepositoryInterface::class, fn() => new DatabaseRememberTokenRepository());
        $this->container->singleton(AppSettingsRepositoryInterface::class, fn() => new DatabaseAppSettingsRepository());
        $this->container->singleton(CronTokenRepositoryInterface::class, fn() => new DatabaseCronTokenRepository());
        $this->container->singleton(LogRepositoryInterface::class, fn() => new DatabaseLogRepository());
        $this->container->singleton(RoleRepositoryInterface::class, fn() => new DatabaseRoleRepository());
        $this->container->singleton(RoleAssignmentRepositoryInterface::class, fn() => new DatabaseRoleAssignmentRepository());
        $this->container->singleton(DevicePushTokenRepositoryInterface::class, fn() => new DatabaseDevicePushTokenRepository());
        $this->container->singleton(BundleRegistryRepositoryInterface::class, fn() => new DatabaseBundleRegistryRepository());
        $this->container->singleton(InstalledBundleRepositoryInterface::class, fn() => new DatabaseInstalledBundleRepository());



        // Traductions (fichiers JSON, voir lang/languages.json et lang/{code}.json). Chaque
        // bundle legacy peut définir ses propres app/Bundles/<Name>/lang/{code}.json, et
        // chaque bundle installé dynamiquement son storage/bundles/<slug>/<version>/lang/{code}.json,
        // tous fusionnés par-dessus ceux du Core (fallback Core si un bundle ne redéfinit pas une clé).
        $this->container->singleton(LanguageRepositoryInterface::class, fn() => new JsonLanguageRepository(BASE_PATH . '/lang'));
        $this->container->singleton(TranslationRepositoryInterface::class, fn() => new JsonTranslationRepository(
            BASE_PATH . '/lang',
            BASE_PATH . '/app/Bundles',
            self::installedBundleRoots(),
        ));
    }

    /** @return string[] Chemins absolus (storage/bundles/<slug>/<version>) de chaque bundle installé actif. */
    private static function installedBundleRoots(): array
    {
        $bundlesDir = storage_path('bundles');
        $roots = [];
        foreach ((new InstalledBundleManifestStore())->all() as $slug => $info) {
            $version = $info['active_version'] ?? null;
            if (is_string($version) && $version !== '') {
                $roots[] = $bundlesDir . '/' . $slug . '/' . $version;
            }
        }
        return $roots;
    }
}
