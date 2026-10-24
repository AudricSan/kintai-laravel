<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\ViewRenderer;

/**
 * Contrôle d'accès admin + permissions fines du RBAC, en un seul middleware
 * (fusion de l'ancien AdminMiddleware + PermissionMiddleware, RBAC-V2).
 *
 * La règle requise par route est déclarée directement sur la route elle-même
 * (paramètre permission: de Router::get/post/..., voir Route::$permission) :
 * une clé de PermissionCatalog, un tableau ['perm' => clé, 'membership' =>
 * true] / ['perm' => clé, 'store_param' => '...'], ou 'public' pour une route
 * volontairement exemptée de contrôle fin (self-service, agrégat, ou déjà
 * protégée par OwnerOnlyMiddleware/requireOwner()).
 *
 * - Owner (is_admin, ponté depuis un rôle système en portée globale) : accès
 *   complet, managed_store_ids = null.
 * - Non-Owner sur une route à permission précise : accordé si un rôle
 *   accorde cette clé (scopée à un store, ou globalement), ou — pour une
 *   règle 'membership' — si l'utilisateur est simplement membre du store
 *   ciblé (porte d'entrée volontairement grossière pour un accès en
 *   libre-service, ex. bundle DailyReport ; la logique fine par ressource
 *   reste vérifiée par le contrôleur). Ce repli 'membership' est vérifié
 *   INDÉPENDAMMENT de toute permission RBAC : un pur membre de store sans
 *   aucun rôle ne doit pas être bloqué par la porte grossière ci-dessous.
 *   managed_store_ids est alors resserré à la portée réelle de CETTE
 *   permission (ou au seul store ciblé pour un repli 'membership') — jamais
 *   à un heuristique global.
 * - Non-Owner sur une route 'public'/sans permission déclarée : accordé s'il
 *   détient au moins une permission RBAC quelque part (managed_store_ids =
 *   l'ensemble de ces stores), sinon redirigé vers /employee — remplace
 *   l'ancien filtre grossier d'AdminMiddleware pour ces pages self-service/
 *   agrégats (ex. admin.requests, chaque section s'auto-filtrant ensuite par
 *   permission dans son propre contrôleur). Si l'utilisateur détient un rôle
 *   système ou un rôle marquant au moins une permission en portée globale
 *   (peu importe laquelle), managed_store_ids = null plutôt que la liste
 *   forcément incomplète d'anyGrantedStoreIds() — sinon une permission comme
 *   photos.view rendue globale sur un rôle store-scope restait, sur ces
 *   routes 'public' (ex. /storage/{path*}, qui sert tous les fichiers
 *   uploadés), à tort bornée au store d'origine de l'affectation.
 *
 * tests/Unit/Core/PermissionMapsTest fait échouer la suite si une route sous
 * ce middleware n'a ni permission précise ni marqueur 'public' explicite.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ViewRenderer $view,
        private readonly StoreUserRepositoryInterface $storeUsers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user    = $request->getAttribute('auth_user') ?? [];
        $isOwner = !empty($user['is_admin']); // Owner (rôle système, ponté sur is_admin par AuthService)

        if ($isOwner) {
            $this->setManagedStoreIds($request, null);
            return $next($request);
        }

        $userId = (int) ($user['id'] ?? 0);
        $rule   = $request->getAttribute('route_permission');
        $key    = is_array($rule) ? (string) $rule['perm'] : $rule;

        if ($key !== null && $key !== 'public') {
            $scoped = $this->permissions->scopedStoreIds($userId, $key);
            if ($scoped !== []) {
                $this->setManagedStoreIds($request, $scoped);
                return $next($request);
            }
            // Affectation de portée globale (rôle non-système accordant la clé partout)
            if ($this->permissions->can($user, $key, null)) {
                $this->setManagedStoreIds($request, null);
                return $next($request);
            }

            // Porte d'entrée volontairement grossière pour un accès en libre-service,
            // INDÉPENDANTE de toute permission RBAC (ex. bundle DailyReport : tout
            // membre du store peut créer/soumettre son propre rapport). Vérifiée avant
            // la porte grossière ci-dessous : un pur membre de store, sans aucune
            // permission RBAC nulle part, doit quand même pouvoir passer ici — la
            // portée fine par ressource reste vérifiée par le contrôleur.
            if (is_array($rule) && !empty($rule['membership'])) {
                $storeParam = $rule['store_param'] ?? 'id';
                $storeId    = (int) ($request->param($storeParam) ?? 0);
                if ($storeId > 0 && $this->storeUsers->findMembership($storeId, $userId) !== null) {
                    $this->setManagedStoreIds($request, [$storeId]);
                    return $next($request);
                }
            }
        }

        // Ni permission précise accordée pour cette route (ni membership), ni route
        // 'public'/sans règle : porte d'entrée grossière — au moins une permission
        // RBAC quelque part (remplace l'ancien AdminMiddleware), sinon /employee.
        //
        // Un rôle système, ou un rôle marquant au moins une permission "Toutes les
        // boutiques", rend cette porte grossière illimitée — anyGrantedStoreIds() ne
        // peut pas le détecter (voir son docblock). Sans ce court-circuit, un
        // utilisateur affecté en scope_type='store' mais dont le rôle accorde une
        // permission comme photos.view en portée globale restait à tort limité à son
        // store d'origine sur toute route 'public' (ex. /storage/{path*}, qui sert
        // aussi bien les images de rapports photo que les fichiers d'import Excel).
        if ($this->permissions->hasAnyGlobalPermissionGrant($user)) {
            $this->setManagedStoreIds($request, null);
        } else {
            $managedIds = $this->permissions->anyGrantedStoreIds($user);
            if ($managedIds === []) {
                return Response::redirect(base_url() . '/employee');
            }
            $this->setManagedStoreIds($request, $managedIds);
        }

        if ($key === null || $key === 'public') {
            return $next($request);
        }

        throw new ForbiddenException(__('error_permission_required', ['key' => $key]));
    }

    /** @param int[]|null $storeIds */
    private function setManagedStoreIds(Request $request, ?array $storeIds): void
    {
        $request->setAttribute('managed_store_ids', $storeIds);
        $this->view->share('managed_store_ids', $storeIds);
    }
}
