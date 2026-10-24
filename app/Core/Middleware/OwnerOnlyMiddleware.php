<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Réserve une route à l'Owner (rôle système, ponté sur is_admin par AuthService).
 * Remplace les 7 méthodes requireOwner() dupliquées à l'identique dans les
 * contrôleurs Owner-only (rôles RBAC, sauvegardes/mises à jour, réinitialisation,
 * bundles, langues, réglages d'instance, synchronisation des docs) — RBAC-V2.
 *
 * Doit être placé après AuthMiddleware (auth_user déjà attaché à la requête).
 * Contrairement à certaines des anciennes implémentations (header()+exit),
 * lève systématiquement une exception — testable, et rendue en JSON ou HTML
 * selon Request::wantsJson() par Application::handleException(), comme le
 * reste de l'application.
 */
final class OwnerOnlyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->getAttribute('auth_user') ?? [];
        if (empty($user['is_admin'])) {
            throw new ForbiddenException(__('error_owner_only'));
        }
        return $next($request);
    }
}
