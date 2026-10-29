<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Core\InstalledBundleManifestStore;
use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserRepositoryInterface::class, fn() => new DatabaseUserRepository());
        $this->app->singleton(StoreRepositoryInterface::class, fn() => new DatabaseStoreRepository());
        $this->app->singleton(StoreUserRepositoryInterface::class, fn() => new DatabaseStoreUserRepository());
        $this->app->singleton(RouteSlugRepositoryInterface::class, fn() => new DatabaseRouteSlugRepository());
        $this->app->singleton(ShiftTypeRepositoryInterface::class, fn() => new DatabaseShiftTypeRepository());
        $this->app->singleton(ShiftRepositoryInterface::class, fn() => new DatabaseShiftRepository());
        $this->app->singleton(ShiftWeekTemplateRepositoryInterface::class, fn() => new DatabaseShiftWeekTemplateRepository());
        $this->app->singleton(TimesheetApprovalRepositoryInterface::class, fn() => new DatabaseTimesheetApprovalRepository());
        $this->app->singleton(AvailabilityRepositoryInterface::class, fn() => new DatabaseAvailabilityRepository());
        $this->app->singleton(UserShiftTypeRateRepositoryInterface::class, fn() => new DatabaseUserShiftTypeRateRepository());
        $this->app->singleton(IcalTokenRepositoryInterface::class, fn() => new DatabaseIcalTokenRepository());
        $this->app->singleton(UserDashboardPrefsRepositoryInterface::class, fn() => new DatabaseUserDashboardPrefsRepository());
        $this->app->singleton(UserDashboardLayoutRepositoryInterface::class, fn() => new DatabaseUserDashboardLayoutRepository());
        $this->app->singleton(RoleDashboardLayoutRepositoryInterface::class, fn() => new DatabaseRoleDashboardLayoutRepository());
        $this->app->singleton(UserNavPrefsRepositoryInterface::class, fn() => new DatabaseUserNavPrefsRepository());
        $this->app->singleton(NotificationRepositoryInterface::class, fn() => new DatabaseNotificationRepository());
        $this->app->singleton(NotificationPreferenceRepositoryInterface::class, fn() => new DatabaseNotificationPreferenceRepository());
        $this->app->singleton(WebPushSubscriptionRepositoryInterface::class, fn() => new DatabaseWebPushSubscriptionRepository());
        $this->app->singleton(CalendarSyncRepositoryInterface::class, fn() => new DatabaseCalendarSyncRepository());
        $this->app->singleton(ApiTokenRepositoryInterface::class, fn() => new DatabaseApiTokenRepository());
        $this->app->singleton(ImportAliasRepositoryInterface::class, fn() => new DatabaseImportAliasRepository());
        $this->app->singleton(PasswordResetRepositoryInterface::class, fn() => new DatabasePasswordResetRepository());
        $this->app->singleton(RememberTokenRepositoryInterface::class, fn() => new DatabaseRememberTokenRepository());
        $this->app->singleton(AppSettingsRepositoryInterface::class, fn() => new DatabaseAppSettingsRepository());
        $this->app->singleton(CronTokenRepositoryInterface::class, fn() => new DatabaseCronTokenRepository());
        $this->app->singleton(LogRepositoryInterface::class, fn() => new DatabaseLogRepository());
        $this->app->singleton(RoleRepositoryInterface::class, fn() => new DatabaseRoleRepository());
        $this->app->singleton(RoleAssignmentRepositoryInterface::class, fn() => new DatabaseRoleAssignmentRepository());
        $this->app->singleton(DevicePushTokenRepositoryInterface::class, fn() => new DatabaseDevicePushTokenRepository());
        $this->app->singleton(BundleRegistryRepositoryInterface::class, fn() => new DatabaseBundleRegistryRepository());
        $this->app->singleton(InstalledBundleRepositoryInterface::class, fn() => new DatabaseInstalledBundleRepository());



        // Traductions (fichiers JSON, voir lang/languages.json et lang/{code}.json). Chaque
        // bundle legacy peut définir ses propres app/Bundles/<Name>/lang/{code}.json, et
        // chaque bundle installé dynamiquement son storage/bundles/<slug>/<version>/lang/{code}.json,
        // tous fusionnés par-dessus ceux du Core (fallback Core si un bundle ne redéfinit pas une clé).
        $this->app->singleton(LanguageRepositoryInterface::class, fn() => new JsonLanguageRepository(BASE_PATH . '/lang'));
        $this->app->singleton(TranslationRepositoryInterface::class, fn() => new JsonTranslationRepository(
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
