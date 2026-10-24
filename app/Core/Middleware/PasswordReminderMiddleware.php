<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\AuthService;
use kintai\Core\Container;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\ViewRenderer;

/**
 * Rappelle de changer le mot de passe par défaut « 0000 » (ou un mot de passe trop court), sans bloquer
 * l'application : un bandeau sur chaque page tant que ce n'est pas fait, et une fenêtre à chaque connexion.
 *
 * Partage avec les vues :
 * - password_change_recommended : le mot de passe de l'utilisateur connecté devrait être changé (bandeau) ;
 * - password_reminder_popup : afficher la fenêtre de rappel (une fois par connexion, sur la première page HTML).
 *
 * L'API (/api/*) n'est pas concernée : elle refuse de délivrer un jeton tant que le mot de passe est trop faible
 * (Api\V1\AuthController::login()).
 */
final class PasswordReminderMiddleware implements MiddlewareInterface
{
    /** Pas de page HTML derrière ces chemins : rien à rappeler, et la fenêtre ne doit pas y être consommée. */
    private const SKIP_PREFIXES = ['/api/', '/cron/', '/assets/', '/bundle-assets/', '/avatar/', '/storage/'];

    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uri = $request->uri();
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
            // La fenêtre est réservée à une vraie page : un appel AJAX (notifications…) ne doit pas la consommer.
            $popup = $request->method() === 'GET' && !$request->wantsJson() && $auth->consumePasswordReminder();

            $view = $this->container->make(ViewRenderer::class);
            $view->share('password_change_recommended', $recommended);
            $view->share('password_reminder_popup', $popup);
        } catch (\Throwable) {
            // Base indisponible ou pas encore migrée : un rappel ne doit jamais empêcher d'afficher la page.
        }

        return $next($request);
    }
}
