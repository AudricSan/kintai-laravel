<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Génère et valide le token CSRF pour toutes les requêtes mutantes hors /api/.
 * Le token est stocké en session et transmis via le champ caché `_token`
 * ou l'en-tête `X-CSRF-Token` pour les requêtes AJAX.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SESSION_KEY = '_csrf_token';
    private const FIELD_NAME  = '_token';

    /**
     * Adresses sans jeton CSRF : elles sont appelées par un client e-mail (désabonnement en un clic, RFC 8058), donc
     * sans session, et se protègent elles-mêmes par une signature dans l'adresse.
     */
    private const EXEMPT_PATHS = ['/notifications/unsubscribe'];

    public function handle(Request $request, Closure $next): Response
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        $realMethod = strtoupper($request->server('REQUEST_METHOD', 'GET'));
        $isApi      = str_starts_with($request->uri(), '/api/') || in_array($request->uri(), self::EXEMPT_PATHS, true);

        if (!$isApi && in_array($realMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->post(self::FIELD_NAME)
                  ?? $request->header('X-CSRF-Token');

            if (!hash_equals($_SESSION[self::SESSION_KEY], (string) $token)) {
                return $this->rejected($request);
            }
        }

        return $next($request);
    }

    /**
     * Jeton absent ou différent : presque toujours une session expirée (page restée ouverte plus longtemps que la
     * durée de session, typiquement la page de connexion). Avant, une page en dur, en français, avec un lien vers « / »
     * (sous-dossier ignoré) et un exit.
     * - appel AJAX : JSON 419 ;
     * - plus personne de connecté dans la session : retour à la connexion avec un message (rien n'est perdu) ;
     * - sinon : page 419 traduite (errors.419), qui renvoie vers la page d'origine.
     */
    private function rejected(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => __('error_419_message'), 'code' => 'CSRF_TOKEN_MISMATCH'], 419);
        }
        if (AuthService::sessionUserId() === null) {
            return Response::redirect(base_url() . '/login?error=expired');
        }
        throw new HttpException(419, '');
    }
}
