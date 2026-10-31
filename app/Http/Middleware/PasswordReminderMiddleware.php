<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Auth\AuthService;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Impose le changement d'un mot de passe temporaire (compte créé ou réinitialisé par un administrateur) ou trop court
 * (audit SEC-02) : tant qu'il n'est pas changé, toute page web renvoie vers le profil, sauf le profil lui-même, la
 * déconnexion et le choix de langue.
 *
 * Partage avec les vues :
 * - password_change_recommended : le mot de passe de l'utilisateur connecté devrait être changé (bandeau) ;
 * - password_reminder_popup : afficher la fenêtre de rappel (une fois par connexion, sur la première page HTML).
 *
 * L'API (/api/*) n'est pas concernée : elle refuse de délivrer un jeton tant que le mot de passe est trop faible
 * (Api\V1\AuthController::login()).
 */
final class PasswordReminderMiddleware
{
    /** Pas de page HTML derrière ces chemins : rien à rappeler, et la fenêtre ne doit pas y être consommée. */
    private const SKIP_PREFIXES = ['/api/', '/cron/', '/assets/', '/bundle-assets/', '/avatar/', '/storage/'];

    /** Pages restant accessibles tant que le mot de passe n'est pas changé. */
    private const ALLOWED_PREFIXES = ['/profile', '/logout', '/lang/'];

    public function __construct(private readonly Container $container) {}

    private static function isAllowedWhilePending(string $uri): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($uri === rtrim($prefix, '/') || str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $uri = $request->slashPath();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return $next($request);
            }
        }

        try {
            /** @var AuthService $auth */
            $auth = $this->container->make(AuthService::class);
            if ($auth->user() === null) {
                return $next($request);
            }
            $recommended = $auth->mustChangePassword();
            if ($recommended && !self::isAllowedWhilePending($uri)) {
                if ($request->wantsJsonResponse()) {
                    return response()->json(['error' => 'password_change_required'], 403);
                }

                return redirect()->away(base_url() . '/profile?tab=info')->with('warning', __('profile_must_change_password_warning'));
            }
            // La fenêtre est réservée à une vraie page : un appel AJAX (notifications…) ne doit pas la consommer.
            $popup = $request->method() === 'GET' && !$request->wantsJsonResponse() && $auth->consumePasswordReminder();

            $view = $this->container->make(ViewFactory::class);
            $view->share('password_change_recommended', $recommended);
            $view->share('password_reminder_popup', $popup);
        } catch (\Throwable) {
            // Base indisponible ou pas encore migrée : un rappel ne doit jamais empêcher d'afficher la page.
        }

        return $next($request);
    }
}
