<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reconnecte automatiquement l'utilisateur via le cookie "rester connecté" (30 jours,
 * voir AuthService::issueRememberToken()) quand la session PHP native est absente ou
 * expirée. Doit s'exécuter après SessionMiddleware (session démarrée) et avant tout
 * middleware qui exige d'être connecté (ex. AuthMiddleware) — placé dans le pipeline
 * global juste après SessionMiddleware pour cette raison.
 */
final class RememberMeMiddleware
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);

        if (!$auth->check()) {
            $auth->attemptViaRememberCookie();
        }

        return $next($request);
    }
}
