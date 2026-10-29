<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Exceptions\HttpException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Security\AttemptCounter;

/**
 * Limiteur générique : MAX_ATTEMPTS requêtes par fenêtre de WINDOW secondes, par IP et par route,
 * QUELLE QUE SOIT leur issue. Convient aux actions rares (mot de passe oublié, signalement d'un
 * problème, réinitialisation). Les routes de connexion utilisent LoginThrottleMiddleware, qui ne
 * compte que les échecs et vise aussi le compte : compter les succès y bloquerait les employés d'un
 * même magasin, qui partagent une IP.
 */
final class RateLimiterMiddleware
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 300;

    public function __construct(private readonly AttemptCounter $counter = new AttemptCounter()) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Nom de la route plutôt que l'URI : sur /reset-password/{token}, l'URI change avec chaque
        // jeton essayé et permettrait de contourner la limite en variant le jeton.
        $target = (string) ($request->routeName() ?? $request->slashPath());
        $key = 'route:' . $request->ip() . ':' . $request->method() . ':' . $target;

        $retryAfter = $this->counter->retryAfter($key, self::MAX_ATTEMPTS, self::WINDOW);
        if ($retryAfter > 0) {
            throw new HttpException(429, __('error_too_many_attempts'), ['Retry-After' => (string) $retryAfter]);
        }

        $this->counter->hit($key, self::WINDOW);

        return $next($request);
    }
}
