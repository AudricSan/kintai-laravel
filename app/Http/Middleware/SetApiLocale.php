<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Langue des messages de l'API : l'en-tête `Accept-Language` (parmi les langues prises en charge), sinon la langue de
 * l'utilisateur du jeton, sinon celle de l'instance. Rend aussi « sanctum » garde par défaut : l'API n'a pas de session.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    public static function apply(Request $request): void
    {
        Auth::shouldUse('sanctum');

        app()->setLocale(self::resolve($request));
    }

    public static function resolve(Request $request): string
    {
        // Sans en-tête, `getLanguages()` renverrait la langue par défaut de la requête : on ne s'en sert pas.
        $languages = trim((string) $request->header('Accept-Language')) !== '' ? $request->getLanguages() : [];

        foreach ($languages as $language) {
            $code = strtolower(substr(str_replace('_', '-', $language), 0, 2));

            if (Locales::isSupported($code)) {
                return $code;
            }
        }

        try {
            $locale = $request->user('sanctum')?->locale;
        } catch (Throwable) {
            $locale = null;
        }

        return Locales::isSupported($locale) ? (string) $locale : (string) config('app.locale');
    }
}
