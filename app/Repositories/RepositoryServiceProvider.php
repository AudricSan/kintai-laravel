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
        $this->app->singleton(ShiftTypeRepositoryInterface::class, fn() => new DatabaseShiftTypeRepository());
        $this->app->singleton(ShiftRepositoryInterface::class, fn() => new DatabaseShiftRepository());
        $this->app->singleton(AvailabilityRepositoryInterface::class, fn() => new DatabaseAvailabilityRepository());
        $this->app->singleton(UserShiftTypeRateRepositoryInterface::class, fn() => new DatabaseUserShiftTypeRateRepository());
        $this->app->singleton(NotificationRepositoryInterface::class, fn() => new DatabaseNotificationRepository());
        $this->app->singleton(AppSettingsRepositoryInterface::class, fn() => new DatabaseAppSettingsRepository());
        $this->app->singleton(LogRepositoryInterface::class, fn() => new DatabaseLogRepository());
        $this->app->singleton(RoleRepositoryInterface::class, fn() => new DatabaseRoleRepository());
        $this->app->singleton(RoleAssignmentRepositoryInterface::class, fn() => new DatabaseRoleAssignmentRepository());

        // Traductions : fichiers JSON de Laravel (lang/{code}.json) ; la liste des langues est lang/languages.json.
        $this->app->singleton(LanguageRepositoryInterface::class, fn() => new JsonLanguageRepository(BASE_PATH . '/lang'));
        $this->app->singleton(TranslationRepositoryInterface::class, fn($app) => new JsonTranslationRepository(
            BASE_PATH . '/lang',
            $app->make('translator'),
        ));
    }

}
