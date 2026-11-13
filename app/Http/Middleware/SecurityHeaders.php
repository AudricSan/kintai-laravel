<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les réponses (web, API, iCal) ; une réponse qui pose déjà l'un d'eux (service worker,
 * flux iCal, API) le garde. HSTS seulement en HTTPS.
 *
 * CSP : `script-src 'self'` plus un nonce par requête (`Vite::cspNonce()`, repris par `@vite`, Livewire et les balises
 * `<script nonce>` des vues) : un script injecté dans une page ne s'exécute pas. `'unsafe-eval'` reste nécessaire tant qu'Alpine
 * tourne en build standard (expressions inline) ; passer au build CSP d'Alpine permettrait de le retirer. Les styles ne sont
 * pas restreints (nombreux `style=""` en ligne). Avec le serveur Vite en développement (`npm run dev`), `script-src` est omis.
 */
class SecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";

    public const SCRIPT_SOURCES = "'self' 'unsafe-eval'";

    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=()';

    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $hot = Vite::isRunningHot();
        $nonce = $hot ? null : Vite::useCspNonce();

        $response = $next($request);

        $csp = self::CONTENT_SECURITY_POLICY.($nonce === null ? '' : '; script-src '.self::SCRIPT_SOURCES." 'nonce-{$nonce}'");

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Content-Security-Policy' => $csp,
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
