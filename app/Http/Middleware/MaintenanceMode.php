<?php

namespace App\Http\Middleware;

use App\Authorization\Authorizer;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Settings\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode maintenance réglé par l'Owner (pas `php artisan down`, qui bloquerait aussi l'Owner et demande un terminal).
 *
 * Activé, tout le monde reçoit une 503 (avec le message de l'Owner et `Retry-After`) sauf :
 * - l'Owner connecté, qui peut donc désactiver la maintenance ;
 * - la connexion (page, action, double authentification, passkey), pour que l'Owner puisse se connecter, et la déconnexion ;
 * - le service worker et le manifeste (PWA) ;
 * - `GET /api/v1/ping` (découverte par l'application mobile) ;
 * - les ressources statiques (servies par le serveur web) et le flux iCal, qui ne passent pas par ce middleware.
 *
 * L'API répond `503 {"error", "code": "maintenance"}`, sauf avec le jeton d'un Owner.
 */
class MaintenanceMode
{
    /** Secondes conseillées avant de réessayer. */
    public const RETRY_AFTER = 300;

    /** Routes web ouvertes pendant la maintenance. */
    private const OPEN_ROUTES = [
        'login', 'login.store', 'logout',
        'two-factor.login', 'two-factor.login.store',
        'passkey.login', 'passkey.login-options',
        'pwa.sw', 'pwa.manifest',
        'api.v1.ping',
    ];

    public function __construct(private readonly AppSettings $settings, private readonly Authorizer $authorizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->maintenanceEnabled() || $request->routeIs(...self::OPEN_ROUTES)) {
            return $next($request);
        }

        $isApi = $request->is('api/*');
        $user = $isApi ? $request->user('sanctum') : Auth::user();

        if ($user instanceof User && $this->authorizer->isOwner($user)) {
            return $next($request);
        }

        $message = $this->settings->maintenanceMessage();
        $message = $message !== '' ? $message : __('The application is under maintenance. Please try again later.');
        $headers = ['Retry-After' => (string) self::RETRY_AFTER];

        if ($isApi) {
            throw new ApiException(503, 'maintenance', $message, $headers);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503, $headers);
        }

        return response()->view('errors.maintenance', [
            'message' => $message,
            'supportEmail' => $this->settings->supportEmail(),
        ], 503, $headers);
    }
}
