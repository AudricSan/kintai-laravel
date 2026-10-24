<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\LocaleCookie;
use kintai\Core\Services\TranslationService;

/**
 * Langue d'affichage de la requête (ordre détaillé dans InstanceLocale) :
 * - connecté : langue du profil, sinon langue de l'instance ;
 * - non connecté : sélecteur (session), dernier compte connecté sur ce navigateur (cookie), navigateur, instance.
 */
final class I18nMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly LanguageRepositoryInterface $languageRepository,
        private readonly ?UserRepositoryInterface $users = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->determineLocale($request);
        $this->translationService->setLocale($locale);

        return $next($request);
    }

    private function determineLocale(Request $request): string
    {
        $activeCodes = array_column($this->languageRepository->findAllActive(), 'code');
        if ($activeCodes === []) {
            return 'en';
        }
        $instance = InstanceLocale::resolve($this->languageRepository);

        // Connecté : le global tourne avant AuthMiddleware, le compte est lu depuis la session (aucun droit en jeu).
        $userId = AuthService::sessionUserId();
        if ($userId !== null && $this->users !== null) {
            $language = (string) ($this->users->findById($userId)['language'] ?? '');
            return in_array($language, $activeCodes, true) ? $language : $instance;
        }

        // Non connecté : sélecteur de la page de connexion.
        if (isset($_SESSION['locale']) && in_array($_SESSION['locale'], $activeCodes, true)) {
            return $_SESSION['locale'];
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
