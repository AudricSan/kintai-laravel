<?php

declare(strict_types=1);

/**
 * Fonctions globales du contrat de bundle (app/Contract/functions.php) qui portent le même nom qu'un helper de Laravel.
 *
 * Laravel ne déclare ses helpers que s'ils n'existent pas encore (garde function_exists()). Ce fichier est donc chargé
 * AVANT eux, en tête de vendor/autoload.php (voir scripts/composer/load-kintai-helpers-first.php, lancé par Composer
 * après chaque génération de l'autoloader) : la version Kintai est celle qui existe partout — application web, Artisan,
 * scripts et tests —, avec la signature et le comportement que le contrat garantit aux bundles.
 *
 * - __()           : traductions JSON de Kintai (TranslationService), paramètres « :nom » ;
 * - storage_path() : dossier storage/ (ou KINTAI_STORAGE_PATH pour les tests), le même que celui de Laravel.
 *
 * Les corps ne référencent les classes qu'à l'appel : ce fichier ne charge rien.
 */

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        // KINTAI_STORAGE_PATH permet aux tests d'isoler le dossier storage
        // (voir BackupServiceTest / UpdateServiceTest) sans toucher au
        // vrai storage/ de l'application.
        $storageDir = $_ENV['KINTAI_STORAGE_PATH'] ?? $_SERVER['KINTAI_STORAGE_PATH'] ?? getenv('KINTAI_STORAGE_PATH');
        if (!$storageDir) {
            $storageDir = dirname(__DIR__) . '/storage';
        }
        return $storageDir . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('__')) {
    /**
     * Traduit une clé donnée.
     */
    function __(string $key, array $replace = []): string
    {
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->has(\kintai\Core\Services\TranslationService::class)) {
                $translationService = $container->make(\kintai\Core\Services\TranslationService::class);
                return $translationService->translate($key, $replace);
            }
        } catch (\Throwable $e) {
            // En cas d'erreur avant que le service soit prêt
        }

        return $key;
    }
}
