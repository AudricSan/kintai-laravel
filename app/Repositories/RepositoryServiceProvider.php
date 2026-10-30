<?php

declare(strict_types=1);

namespace App\Repositories;

use App\InstalledBundleManifestStore;
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
        $this->app->singleton(AvailabilityRepositoryInterface::class, fn() => new DatabaseAvailabilityRepository());
        $this->app->singleton(UserShiftTypeRateRepositoryInterface::class, fn() => new DatabaseUserShiftTypeRateRepository());
        $this->app->singleton(IcalTokenRepositoryInterface::class, fn() => new DatabaseIcalTokenRepository());
        $this->app->singleton(UserDashboardPrefsRepositoryInterface::class, fn() => new DatabaseUserDashboardPrefsRepository());
        $this->app->singleton(UserDashboardLayoutRepositoryInterface::class, fn() => new DatabaseUserDashboardLayoutRepository());
        $this->app->singleton(UserNavPrefsRepositoryInterface::class, fn() => new DatabaseUserNavPrefsRepository());
        $this->app->singleton(NotificationRepositoryInterface::class, fn() => new DatabaseNotificationRepository());
        $this->app->singleton(WebPushSubscriptionRepositoryInterface::class, fn() => new DatabaseWebPushSubscriptionRepository());
        $this->app->singleton(CalendarSyncRepositoryInterface::class, fn() => new DatabaseCalendarSyncRepository());
        $this->app->singleton(ApiTokenRepositoryInterface::class, fn() => new DatabaseApiTokenRepository());
        $this->app->singleton(AppSettingsRepositoryInterface::class, fn() => new DatabaseAppSettingsRepository());
        $this->app->singleton(LogRepositoryInterface::class, fn() => new DatabaseLogRepository());
        $this->app->singleton(RoleRepositoryInterface::class, fn() => new DatabaseRoleRepository());
        $this->app->singleton(RoleAssignmentRepositoryInterface::class, fn() => new DatabaseRoleAssignmentRepository());
        $this->app->singleton(DevicePushTokenRepositoryInterface::class, fn() => new DatabaseDevicePushTokenRepository());
        $this->app->singleton(InstalledBundleRepositoryInterface::class, fn() => new DatabaseInstalledBundleRepository());

        // Traductions : fichiers JSON de Laravel (lang/{code}.json) ; la liste des langues est lang/languages.json.
        $this->app->singleton(LanguageRepositoryInterface::class, fn() => new JsonLanguageRepository(BASE_PATH . '/lang'));
        $this->app->singleton(TranslationRepositoryInterface::class, fn($app) => new JsonTranslationRepository(
            BASE_PATH . '/lang',
            $app->make('translator'),
        ));
    }

}
