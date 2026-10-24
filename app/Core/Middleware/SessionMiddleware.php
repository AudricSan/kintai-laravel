<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Request;
use kintai\Core\Response;

final class SessionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        self::start($request->isSecure());
        // Ancienne copie de la ligne utilisateur (hash du mot de passe compris), écrite par AuthController jusqu'au
        // 01/10/2026 sans jamais être relue : on la retire des sessions encore ouvertes.
        unset($_SESSION['auth_user']);

        return $next($request);
    }

    /** Ouvre la session de l'application (paramètres de config/session.php) si elle ne l'est pas déjà. */
    public static function start(bool $secureRequest): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $configFile = dirname(__DIR__, 3) . '/config/session.php';
            $config = file_exists($configFile) ? require $configFile : [];

            $secure = (bool) ($config['secure'] ?? false) || $secureRequest;

            session_set_cookie_params([
                'lifetime' => $config['lifetime'] ?? 7200,
                'path'     => $config['path'] ?? '/',
                'secure'   => $secure,
                'httponly' => $config['httponly'] ?? true,
                'samesite' => $config['samesite'] ?? 'Lax',
            ]);

            session_name($config['name'] ?? 'kintai_session');
            session_start();
        }
    }
}
