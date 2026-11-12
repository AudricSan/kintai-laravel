<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé perd l'accès à l'API : ses jetons sont supprimés et la requête est refusée (401).
 */
class EnsureApiAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user !== null && ! $user->is_active) {
            $user->tokens()->delete();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
