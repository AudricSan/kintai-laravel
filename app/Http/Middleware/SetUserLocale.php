<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique la langue choisie par l'utilisateur connecté (null = langue de l'instance).
 */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Auth::user()?->locale;

        app()->setLocale(Locales::isSupported($locale) ? $locale : (string) config('app.locale'));

        return $next($request);
    }
}
