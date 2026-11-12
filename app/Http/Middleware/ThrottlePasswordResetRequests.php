<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite les demandes de lien de réinitialisation (5 par minute et par IP). Appliqué à toutes les routes Fortify,
 * n'agit que sur `password.email`.
 */
class ThrottlePasswordResetRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route()?->getName() !== 'password.email') {
            return $next($request);
        }

        $key = 'password-email:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, '', ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
