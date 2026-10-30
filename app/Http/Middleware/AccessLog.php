<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\AppSettingsService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Journal d'accès automatique (une ligne par requête, canal « access »), écrit une fois la réponse envoyée.
 *
 * Premier middleware global : la réponse qu'il voit est la dernière, pages d'erreur comprises.
 */
final class AccessLog
{
    private const STARTED_AT = 'kintai.started_at';

    /**
     * Chemins exclus : polling/streaming haute fréquence (notifications, SSE) qui saturerait activity_log sans valeur
     * d'audit ajoutée, et le health-check API destiné aux moniteurs externes.
     */
    private const EXCLUDED_URIS = [
        '/notifications/poll',
        '/api/v1/ping',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $uri = $request->slashPath();
        if (str_ends_with($uri, '/stream') || in_array($uri, self::EXCLUDED_URIS, true)) {
            return;
        }

        try {
            if (!app(AppSettingsService::class)->accessLogEnabled()) {
                return;
            }

            $status = $response->getStatusCode();
            $level = match (true) {
                $status >= 500 => 'error',
                $status >= 400 => 'warning',
                default        => 'info',
            };
            $durationMs = (int) round((microtime(true) - (float) $request->attributes->get(self::STARTED_AT, microtime(true))) * 1000);

            Log::channel('access')->log($level, sprintf('%s %s', $request->method(), $uri), [
                'response_status' => $status,
                'duration_ms'     => $durationMs,
            ]);
        } catch (Throwable) {
            // Le logging ne doit jamais faire planter l'application
        }
    }
}
