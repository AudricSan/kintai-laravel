<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Autorisation RBAC de l'API v1. Doit être placé après ApiAuthMiddleware
 * (auth_user déjà attaché à partir du token Bearer).
 *
 * La règle par route est déclarée directement sur la route elle-même
 * (paramètre permission: de Router::get/post/..., voir Route::$permission).
 * Contrairement à l'utilisateur de session web, l'auth_user attaché ici est
 * la ligne brute de la table users : le flag legacy is_admin n'est PAS
 * consulté — un Owner passe parce que son affectation au rôle système
 * accorde toutes les clés via PermissionService.
 */
final class ApiPermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly StoreUserRepositoryInterface $storeUsers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rule = $request->getAttribute('route_permission');
        if ($rule === null || $rule === 'public') {
            return $next($request);
        }

        $user   = $request->getAttribute('auth_user') ?? [];
        $userId = (int) ($user['id'] ?? 0);

        $permissionKey     = is_array($rule) ? (string) $rule['perm'] : $rule;
        $selfParam         = is_array($rule) ? ($rule['self'] ?? null) : null;
        $requireMembership = is_array($rule) && !empty($rule['membership']);

        // Ressource propre : l'id d'utilisateur ciblé (paramètre de route ou
        // query) est celui du porteur du token → pas de permission exigée.
        if ($selfParam !== null && $this->targetedUserId($request, $selfParam) === $userId) {
            return $next($request);
        }

        $storeId = $this->targetedStoreId($request);

        if ($this->permissions->can($user, $permissionKey, $storeId)) {
            return $next($request);
        }

        // Porte d'entrée volontairement grossière pour un accès en libre-service
        // (ex. bundle DailyReport : tout membre du store peut créer/soumettre son
        // propre rapport sans permission RBAC dédiée). La logique fine par
        // ressource (statut, auteur) reste vérifiée par le contrôleur.
        if ($requireMembership && $storeId !== null
            && $this->storeUsers->findMembership($storeId, $userId) !== null) {
            return $next($request);
        }

        return Response::json([
            'error' => __('error_api_permission_insufficient', ['key' => $permissionKey]),
            'code'  => 'FORBIDDEN',
        ], 403);
    }

    /**
     * Id utilisateur ciblé par la requête via le paramètre nommé (route, puis
     * query, puis corps de requête — les endpoints d'action comme clock-in
     * portent leur user_id dans le corps JSON).
     */
    private function targetedUserId(Request $request, string $param): ?int
    {
        $value = $request->param($param)
            ?? $request->query($param)
            ?? $request->post($param)
            ?? $request->json($param);
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * Store ciblé par la requête : paramètre de route store_id, sinon query,
     * sinon corps de requête. Null = aucune portée précise (une affectation
     * accordant la clé n'importe où suffit alors).
     */
    private function targetedStoreId(Request $request): ?int
    {
        $value = $request->param('store_id')
            ?? $request->query('store_id')
            ?? $request->post('store_id')
            ?? $request->json('store_id');
        $storeId = $value !== null && $value !== '' ? (int) $value : 0;
        return $storeId > 0 ? $storeId : null;
    }
}
