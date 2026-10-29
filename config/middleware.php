<?php

declare(strict_types=1);

return [
    // Applied to every request
    'global' => [
        \App\Http\Middleware\SessionMiddleware::class,
        \App\Http\Middleware\RememberMeMiddleware::class,
        \App\Http\Middleware\SecurityHeadersMiddleware::class,
        // Limitation de débit de /api/* (toutes routes, bundles compris), avant tout le reste du traitement.
        \App\Http\Middleware\ApiRateLimitMiddleware::class,
        // Langue avant le contrôle CSRF : son refus (session expirée) s'affiche traduit.
        \App\Http\Middleware\I18nMiddleware::class,
        \App\Http\Middleware\CsrfMiddleware::class,
        \App\Http\Middleware\AppSettingsMiddleware::class,
        \App\Http\Middleware\MaintenanceModeMiddleware::class,
        \App\Http\Middleware\PasswordReminderMiddleware::class,
        // Variables de vue des bundles (Bundle::viewVariables()) et managed_store_ids pour la navigation.
        \App\Http\Middleware\BundleViewDataMiddleware::class,
        \App\Http\Middleware\NotificationMiddleware::class,
    ],
];
