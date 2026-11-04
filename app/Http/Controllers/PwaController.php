<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Fichiers PWA servis par des routes (pas de fichiers statiques) : le service worker et le manifeste dépendent de
 * l'URL de déploiement, et les tests peuvent les atteindre.
 */
class PwaController extends Controller
{
    /**
     * Service worker : notifications push, clic sur une notification, renouvellement de l'abonnement.
     * `Service-Worker-Allowed` autorise la portée « / » ; jamais de cache HTTP, pour que les mises à jour arrivent.
     */
    public function serviceWorker(): Response
    {
        return response((string) file_get_contents(resource_path('pwa/sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'id' => url('/'),
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'lang' => app()->getLocale(),
            'background_color' => '#ffffff',
            'theme_color' => '#18181b',
            'icons' => [
                ['src' => asset('images/brand/kintai-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/brand/kintai-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/brand/kintai-512-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
