<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use Illuminate\Container\Container;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware d'authentification API Bearer token.
 * Lit le header Authorization: Bearer <token>, valide le token,
 * charge l'utilisateur et l'attache à la requête.
 */
final class ApiAuthMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return response()->json(['error' => __('error_token_missing'), 'code' => 'MISSING_TOKEN'], 401);
        }

        /** @var ApiTokenRepositoryInterface $tokens */
        $tokens = $this->container->make(ApiTokenRepositoryInterface::class);
        $record = $tokens->findByToken(hash_token($token));

        if ($record === null) {
            return response()->json(['error' => __('error_token_invalid'), 'code' => 'INVALID_TOKEN'], 401);
        }

        if (!empty($record['expires_at']) && strtotime($record['expires_at']) < time()) {
            return response()->json(['error' => __('error_token_expired'), 'code' => 'EXPIRED_TOKEN'], 401);
        }

        /** @var UserRepositoryInterface $users */
        $users = $this->container->make(UserRepositoryInterface::class);
        $user  = $users->findById((int) $record['user_id']);

        if ($user === null || empty($user['is_active']) || !empty($user['deleted_at'])) {
            return response()->json(['error' => __('error_user_inactive'), 'code' => 'INACTIVE_USER'], 401);
        }

        // Mettre à jour last_used_at sans bloquer la requête en cas d'échec
        try {
            $tokens->save(array_merge($record, ['last_used_at' => date('Y-m-d H:i:s')]));
        } catch (\Throwable) {
        }

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('api_token', $record);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization') ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }
}
