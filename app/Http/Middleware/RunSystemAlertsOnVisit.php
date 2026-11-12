<?php

namespace App\Http\Middleware;

use App\Authorization\Authorizer;
use App\Models\User;
use App\Settings\AppSettings;
use App\System\SystemAlerts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Filet des instances dont le planificateur ne tourne pas : quand un Owner charge une page, les alertes système sont
 * vérifiées, au plus une fois par heure. Le travail se fait à la fin de la requête (`terminate`, la réponse est déjà partie),
 * les détecteurs sont locaux (aucun appel réseau) et rien ne peut faire échouer la page.
 */
class RunSystemAlertsOnVisit
{
    public function __construct(private readonly AppSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('kintai.system_alerts_on_visit', true)) {
            return;
        }

        try {
            // Test le plus économique d'abord : la date de la dernière passe vient du cache des réglages.
            $last = $this->settings->systemAlertsLastRun();

            if ($last > 0 && now()->getTimestamp() - $last < SystemAlerts::MIN_INTERVAL) {
                return;
            }

            $user = Auth::user();

            if ($user instanceof User && app(Authorizer::class)->isOwner($user)) {
                app(SystemAlerts::class)->runIfDue();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
