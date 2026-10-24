<?php

declare(strict_types=1);

return [
    // Applied to every request
    'global' => [
        \kintai\Core\Middleware\SessionMiddleware::class,
        \kintai\Core\Middleware\RememberMeMiddleware::class,
        \kintai\Core\Middleware\SecurityHeadersMiddleware::class,
        // Limitation de débit de /api/* (toutes routes, bundles compris), avant tout le reste du traitement.
        \kintai\Core\Middleware\ApiRateLimitMiddleware::class,
        // Langue avant le contrôle CSRF : son refus (session expirée) s'affiche traduit.
        \kintai\Core\Middleware\I18nMiddleware::class,
        \kintai\Core\Middleware\CsrfMiddleware::class,
        \kintai\Core\Middleware\AppSettingsMiddleware::class,
        \kintai\Core\Middleware\MaintenanceModeMiddleware::class,
        \kintai\Core\Middleware\PasswordReminderMiddleware::class,
        // Variables de vue des bundles (Bundle::viewVariables()) et managed_store_ids pour la navigation.
        \kintai\Core\Middleware\BundleViewDataMiddleware::class,
        \kintai\Core\Middleware\NotificationMiddleware::class,
    ],
];
