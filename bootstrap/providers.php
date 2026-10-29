<?php

declare(strict_types=1);

// L'ordre compte : chaque fournisseur s'appuie sur les liaisons du précédent.
return [
    kintai\Providers\KintaiServiceProvider::class,
    kintai\Core\Database\DatabaseServiceProvider::class,
    kintai\Core\Repositories\RepositoryServiceProvider::class,
    kintai\Core\Auth\AuthServiceProvider::class,
    kintai\Core\Services\AppServiceProvider::class,
    kintai\Core\LicenseServiceProvider::class,
    kintai\Core\BundleServiceProvider::class,
];
