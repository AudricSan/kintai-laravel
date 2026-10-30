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

        // Traductions : fichiers JSON de Laravel (lang/{code}.json) ; la liste des langues est lang/languages.json.
        $this->app->singleton(LanguageRepositoryInterface::class, fn() => new JsonLanguageRepository(BASE_PATH . '/lang'));
        $this->app->singleton(TranslationRepositoryInterface::class, fn($app) => new JsonTranslationRepository(
            BASE_PATH . '/lang',
            $app->make('translator'),
        ));
    }

}
