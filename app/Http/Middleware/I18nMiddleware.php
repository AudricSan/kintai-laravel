<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Auth\AuthService;
use App\Models\Language;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\InstanceLocale;
use App\Services\LocaleCookie;

/**
 * Langue d'affichage de la requête (ordre détaillé dans InstanceLocale) :
 * - connecté : langue du profil, sinon langue de l'instance ;
 * - non connecté : sélecteur (session), dernier compte connecté sur ce navigateur (cookie), navigateur, instance.
 */
final class I18nMiddleware
{
    public function __construct(
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->determineLocale($request);
        app()->setLocale($locale);

        return $next($request);
    }

    private function determineLocale(Request $request): string
    {
        $activeCodes = array_column(Language::findAllActive(), 'code');
        if ($activeCodes === []) {
            return 'en';
        }
        $instance = InstanceLocale::resolve();

        // Connecté : le global tourne avant AuthMiddleware, le compte est lu depuis la session (aucun droit en jeu).
        $userId = AuthService::sessionUserId();
        if ($userId !== null) {
            $language = (string) (User::findById($userId)['language'] ?? '');
            return in_array($language, $activeCodes, true) ? $language : $instance;
        }

        // Non connecté : sélecteur de la page de connexion.
        if (in_array(session()->get("locale"), $activeCodes, true)) {
            return session()->get("locale");
        }

        // Langue du dernier compte connecté sur ce navigateur.
        $remembered = LocaleCookie::read();
        if ($remembered !== null && in_array($remembered, $activeCodes, true)) {
            return $remembered;
        }

        // Langue du navigateur.
        $acceptLang = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($acceptLang !== '') {
            $primary = strtolower(substr(trim(explode(',', $acceptLang)[0]), 0, 2));
            if (in_array($primary, $activeCodes, true)) {
                return $primary;
            }
        }

        return $instance;
    }
}
