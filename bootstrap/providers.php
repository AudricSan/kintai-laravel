<?php

declare(strict_types=1);

// L'ordre compte : chaque fournisseur s'appuie sur les liaisons du précédent.
return [
    App\Providers\KintaiServiceProvider::class,
    App\Database\DatabaseServiceProvider::class,
    App\Repositories\RepositoryServiceProvider::class,
    App\Auth\AuthServiceProvider::class,
    App\Services\AppServiceProvider::class,
    App\LicenseServiceProvider::class,
    App\BundleServiceProvider::class,
];
