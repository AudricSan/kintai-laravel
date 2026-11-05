<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les réponses (web, API, iCal) ; une réponse qui pose déjà l'un d'eux (service worker,
 * flux iCal, API) le garde. HSTS seulement en HTTPS.
 *
 * La CSP est volontairement de base (`frame-ancestors`, `object-src`, `base-uri`, `form-action`) : une CSP complète sur
 * les scripts exigerait `unsafe-eval` pour Alpine (build standard) ou un build CSP de Livewire/Flux ; elle est reportée à
 * la revue de sécurité de la phase 8.
 */
class SecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";

    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=()';

    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
