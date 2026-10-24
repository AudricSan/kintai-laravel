<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Security\CspNonce;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nonce tiré AVANT le rendu des vues (elles l'écrivent dans leurs <script>) et repris dans l'en-tête.
        $nonce = CspNonce::renew();

        $response = $next($request);

        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        // form-action autorise aussi https://github.com : le formulaire "Signaler un problème" du footer
        // (SupportController::reportIssue) redirige vers GithubIssueService::fallbackUrl() quand aucun jeton
        // GITHUB_ISSUES_TOKEN n'est configuré sur l'instance — sans cette exception, Chrome bloque
        // silencieusement cette redirection cross-origin (violation "form-action", aucune erreur visible,
        // le clic sur "Envoyer" ne fait rien).
        // Pas de 'unsafe-inline' pour les scripts : ni <script> inline sans nonce, ni attribut onclick=/onchange=/…
        // (les vues passent par des attributs data-* gérés par public/assets/js/modules/csp-actions.js).
        // Pas de 'unsafe-inline' pour les styles non plus : un attribut style="…" est ignoré, un <style> doit porter le
        // nonce. Les valeurs dynamiques passent par style_class() (kintai\UI\InlineStyles), émises dans un <style nonce>.
        // Modifier element.style en JavaScript (CSSOM) n'est pas concerné.
        $response->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data: blob:; font-src 'self'; form-action 'self' https://github.com; frame-ancestors 'self'; object-src 'none'");

        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}