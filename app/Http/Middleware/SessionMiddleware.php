<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Démarre la session de Laravel (pilote de config/session.php) pour la requête, la sauvegarde ensuite et pose son
 * cookie. Remplacé par StartSession de Laravel quand les routes seront natives.
 */
final class SessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = app('session.store');
        if (config('session.driver') === 'file') {
            File::ensureDirectoryExists((string) config('session.files'));
        }
        $id = $request->cookie($session->getName());
        if (is_string($id) && $id !== '') {
            $session->setId($id);
        }
        $session->start();

        try {
            return $next($request);
        } finally {
            $session->save();
            $this->queueCookie($session, $request);
        }
    }

    /** Cookie de session, à chaque réponse : sa durée est glissante, comme le rafraîchissait Laravel. */
    private function queueCookie(Session $session, Request $request): void
    {
        if (headers_sent()) {
            return;
        }

        $config = config('session');
        $secure = $config['secure'] ?? null;
        setcookie($session->getName(), $session->getId(), [
            'expires'  => ($config['expire_on_close'] ?? false) ? 0 : time() + (int) $config['lifetime'] * 60,
            'path'     => (string) $config['path'],
            'domain'   => $config['domain'] ?: '',
            'secure'   => $secure === null ? $request->isSecure() : (bool) $secure,
            'httponly' => (bool) $config['http_only'],
            'samesite' => (string) ($config['same_site'] ?? 'lax'),
        ]);
    }
}
