<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use kintai\Core\Container;
use kintai\Core\Exceptions\ServiceUnavailableException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;

/**
 * Bloque les pages web par une 503 quand le mode maintenance est activé.
 *
 * L'API (/api/*) et les endpoints cron externes (/cron/*) restent joignables
 * pour ne pas casser les intégrations pendant la fenêtre de mise à jour.
 * L'Owner garde toujours un accès complet (y compris pour se connecter via
 * /login), afin de pouvoir désactiver la maintenance ou vérifier le site.
 */
final class MaintenanceModeMiddleware implements MiddlewareInterface
{
    private const BYPASS_PREFIXES = ['/api/', '/cron/'];

    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uri = $request->uri();

        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return $next($request);
            }
        }

        try {
            $settings = $this->container->make(AppSettingsService::class);
        } catch (\Throwable) {
            // Table absente (avant migration) ou DB indisponible : ne jamais bloquer le site.
            return $next($request);
        }

        if (!$settings->maintenanceModeEnabled()) {
            return $next($request);
        }

        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);
        if ($auth->isAdmin()) {
            return $next($request);
        }

        if ($uri === '/login') {
            return $next($request);
        }

        $message = $settings->maintenanceMessage();
        throw new ServiceUnavailableException($message !== '' ? $message : __('error_503_message'));
    }
}
