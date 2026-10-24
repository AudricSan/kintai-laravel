<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionCatalog;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Validation\ColorRule;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

/**
 * Gestion des rôles dynamiques (RBAC). Réservée à l'Owner. Les permissions
 * cochées ici sont lues par PermissionService pour l'autorisation réelle de
 * chaque route ; le champ is_manager est lu séparément par AuthService pour
 * décider si ce rôle affiche la navigation manager (indépendant des
 * permissions accordées, voir AuthService::roleIsManagerType()).
 */
final class AdminRoleController
{
    use HasBaseUrl;

    /**
     * 'update' n'est pas réutilisable tel quel comme clé de traduction : elle
     * désigne déjà "Mises à jour" (mises à jour logicielles) ailleurs dans
     * l'app. Cette table associe chaque action du catalogue à sa propre clé.
     */
    private const ACTION_LABEL_KEYS = [
        'view'     => 'view',
        'create'   => 'create',
        'update'   => 'edit',
        'delete'   => 'delete',
        'import'   => 'perm_action_import',
        'export'   => 'perm_action_export',
        'generate' => 'perm_action_generate',
        'approve'  => 'perm_action_approve',
        'publish'  => 'perm_action_publish',
        'submit'   => 'perm_action_submit',
        'send'     => 'perm_action_send',
        'manage'   => 'perm_action_manage',
    ];

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly RoleRepositoryInterface $roles,
        private readonly RoleAssignmentRepositoryInterface $assignments,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly AuditLogger $auditLogger,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly RoleAssignmentSyncService $roleSync,
        private readonly OwnerGuard $ownerGuard,
    ) {}

    /** GET /admin/roles */
    public function roles(Request $request): Response
    {

        $roles = $this->roles->findAll();
        $counts = [];
        foreach ($roles as &$role) {
            $counts[(int) $role['id']] = count($this->assignments->findByRole((int) $role['id']));
            $role['description'] = $this->resolveDescription($role);
        }
        unset($role);

        return Response::html($this->view->render('system.roles', [
            'title'             => __('roles'),
            'roles'             => $roles,
            'assignment_counts' => $counts,
            'success'           => isset($_GET['success']),
        ], 'layout.app'));
    }

    /** GET /admin/roles/create */
    public function createRole(Request $request): Response
    {

        return Response::html($this->view->render('system.roles-form', [
            'title'                 => __('new_role'),
            'mode'                  => 'create',
            'role'                  => [],
            'permission_categories' => $this->visiblePermissionCategories(),
            'action_label_keys'     => self::ACTION_LABEL_KEYS,
            'granted_permissions'   => [],
            'granted_global_permissions' => [],
            'holders'               => [],
        ], 'layout.app'));
    }

    /** POST /admin/roles/create */
    public function storeRole(Request $request): Response
    {

        $name = trim($request->post('name', ''));
        $slug = $this->uniqueSlug($this->slugify($name));
        if ($name === '' || $slug === '') {
            return Response::redirect($this->base() . '/admin/roles/create?error=invalid_name');
        }

        ColorRule::assert($request->post('color', ''), 'color', true);

        $saved = $this->roles->save([
            'name'        => $name,
            'slug'        => $slug,
            'color'       => $request->post('color', '') ?: null,
            'description' => trim($request->post('description', '')) ?: null,
            'is_system'   => 0,
            'is_manager'  => $request->post('is_manager') ? 1 : 0,
        ]);

        $permissions = $this->postedPermissions($request);
        $globalPermissions = $this->postedGlobalScopeKeys($request);
        $this->roles->savePermissions((int) $saved['id'], $permissions, $globalPermissions);

        $this->auditLogger->log($request, 'role.created', 'role', (int) $saved['id'], [
            'name'              => $name,
            'permissions'       => $permissions,
            'global_permissions' => $globalPermissions,
        ]);
        return Response::redirect($this->base() . '/admin/roles?success=created');
    }

    /** GET /admin/roles/{id}/edit */
    public function editRole(Request $request): Response
    {
        $role = $this->findRoleOrFail($request);
        $role['description'] = $this->resolveDescription($role);
        $isOwnerRole = ($role['slug'] ?? null) === 'owner';

        return Response::html($this->view->render('system.roles-form', [
            'title'                 => 'Modifier ' . htmlspecialchars($role['name'] ?? ''),
            'mode'                  => 'edit',
            'role'                  => $role,
            'permission_categories' => $this->visiblePermissionCategories(),
            'action_label_keys'     => self::ACTION_LABEL_KEYS,
            'granted_permissions'   => $this->roles->getPermissions((int) $role['id']),
            'granted_global_permissions' => $this->roles->getGlobalPermissionKeys((int) $role['id']),
            'holders'               => $this->roleHolders((int) $role['id']),
            'assignable_users'      => $isOwnerRole ? $this->activeUsers() : $this->assignableHolderCandidates(),
        ], 'layout.app'));
    }

    /** POST /admin/roles/{id}/edit */
    public function updateRole(Request $request): Response
    {
        $role = $this->findRoleOrFail($request);
        if (!empty($role['is_system'])) {
            throw new ForbiddenException(__('error_owner_role_immutable'));
        }

        $name = trim($request->post('name', $role['name'] ?? ''));
        if ($name === '') {
            return Response::redirect($this->base() . '/admin/roles/' . $role['id'] . '/edit?error=invalid_name');
        }

        ColorRule::assert($request->post('color', ''), 'color', true);

        // Champ absent de la requête : valeur actuelle conservée (audit du 13/10/2026, M12).
        $newRole = $this->roles->save([
            'id'          => $role['id'],
            'name'        => $name,
            'color'       => $request->hasPost('color') ? ($request->post('color', '') ?: null) : ($role['color'] ?? null),
            'description' => $request->hasPost('description') ? (trim((string) $request->post('description', '')) ?: null) : ($role['description'] ?? null),
            'is_manager'  => $request->postFlag('is_manager', $role['is_manager'] ?? 0),
        ]);

        $oldPermissions = $this->roles->getPermissions((int) $role['id']);
        $oldGlobalPermissions = $this->roles->getGlobalPermissionKeys((int) $role['id']);
        // Les catégories des bundles désactivés sont masquées du formulaire :
        // leurs clés déjà accordées (et leur portée globale/locale) sont
        // préservées telles quelles, pour réapparaître si le bundle est
        // réactivé.
        $hiddenKeys = array_values(array_filter(
            $oldPermissions,
            fn(string $key): bool => !$this->isCategoryVisible(explode('.', $key)[0])
        ));
        $hiddenGlobalKeys = array_intersect($oldGlobalPermissions, $hiddenKeys);
        $newPermissions = array_values(array_unique(array_merge($this->postedPermissions($request), $hiddenKeys)));
        $newGlobalPermissions = array_values(array_unique(array_merge($this->postedGlobalScopeKeys($request), $hiddenGlobalKeys)));
        $this->roles->savePermissions((int) $role['id'], $newPermissions, $newGlobalPermissions);

        $this->auditLogger->logUpdate(
            $request,
            'role.updated',
            'role',
            (int) $role['id'],
            $role + ['permissions' => $oldPermissions, 'global_permissions' => $oldGlobalPermissions],
            $newRole + ['permissions' => $newPermissions, 'global_permissions' => $newGlobalPermissions],
            [],
        );
        return Response::redirect($this->base() . '/admin/roles?success=updated');
    }

    /** POST /admin/roles/{id}/delete */
    public function deleteRole(Request $request): Response
    {
        $role = $this->findRoleOrFail($request);

        if (!empty($role['is_system'])) {
            throw new ForbiddenException(__('error_owner_role_undeletable'));
        }
        if (count($this->assignments->findByRole((int) $role['id'])) > 0) {
            return Response::redirect($this->base() . '/admin/roles?error=role_in_use');
        }

        $this->roles->delete((int) $role['id']);
        $this->auditLogger->log($request, 'role.deleted', 'role', (int) $role['id'], [
            'name' => $role['name'] ?? null,
        ]);
        return Response::redirect($this->base() . '/admin/roles?success=deleted');
    }

    /**
     * POST /admin/roles/{id}/holders — ajoute un ou plusieurs employés à ce
     * rôle. Cas du rôle système Owner (portée globale) : délègue à
     * RoleAssignmentSyncService::syncOwnerRole(), la même logique que la case
     * "Owner" du formulaire employé — aucune notion de magasin. Pour tout
     * autre rôle (store-scope) : appliqué à chaque magasin dont l'employé est
     * déjà membre (remplace toute autre affectation qu'il y détenait — voir
     * RoleAssignmentSyncService::syncStoreRoleById()) ; un employé sans aucun
     * magasin est ignoré, il faut d'abord l'y affecter depuis sa fiche.
     */
    public function addHolder(Request $request): Response
    {
        $role = $this->findRoleOrFail($request);
        $isOwnerRole = ($role['slug'] ?? null) === 'owner';
        if (!empty($role['is_system']) && !$isOwnerRole) {
            throw new ForbiddenException(__('error_owner_role_immutable'));
        }

        $userIds = $this->postedUserIds($request);
        $validUserIds = [];
        foreach ($userIds as $userId) {
            if ($this->users->findById($userId) === null) {
                continue;
            }
            if ($isOwnerRole) {
                $this->roleSync->syncOwnerRole($userId, true);
                $validUserIds[] = $userId;
                continue;
            }
            $memberships = $this->storeUsers->findByUser($userId);
            if ($memberships === []) {
                continue;
            }
            foreach ($memberships as $membership) {
                $this->roleSync->syncStoreRoleById($userId, (int) $membership['store_id'], (int) $role['id']);
            }
            $validUserIds[] = $userId;
        }

        if ($validUserIds === []) {
            return Response::redirect($this->base() . '/admin/roles/' . $role['id'] . '/edit?error=invalid_holder');
        }

        $this->auditLogger->log($request, 'role.holder_added', 'role', (int) $role['id'], [
            'user_ids' => $validUserIds,
        ]);

        return Response::redirect($this->base() . '/admin/roles/' . $role['id'] . '/edit?success=holder_added');
    }

    /** POST /admin/roles/{id}/holders/{assignmentId}/delete */
    public function removeHolder(Request $request): Response
    {
        $role = $this->findRoleOrFail($request);
        $isOwnerRole = ($role['slug'] ?? null) === 'owner';
        if (!empty($role['is_system']) && !$isOwnerRole) {
            throw new ForbiddenException(__('error_owner_role_immutable'));
        }

        $assignment = $this->assignments->findById((int) $request->param('assignmentId'));
        if ($assignment === null || (int) $assignment['role_id'] !== (int) $role['id']) {
            throw new NotFoundException(__('error_resource_not_found'));
        }

        $userId = (int) $assignment['user_id'];
        if ($isOwnerRole) {
            $this->ownerGuard->assertCanRemoveOwner((int) ($request->getAttribute('auth_user')['id'] ?? 0), $userId);
            $this->roleSync->syncOwnerRole($userId, false);
        } else {
            $this->roleSync->revokeStoreRole($userId, (int) $assignment['scope_id']);
        }
        $this->auditLogger->log($request, 'role.holder_removed', 'role', (int) $role['id'], [
            'user_id'  => $userId,
            'store_id' => $assignment['scope_id'],
        ]);

        return Response::redirect($this->base() . '/admin/roles/' . $role['id'] . '/edit?success=holder_removed');
    }

    /**
     * Description affichée pour un rôle : celle en base pour un rôle
     * personnalisé, ou une clé de traduction pour le rôle système Owner —
     * dont la description est structurelle (non modifiable par l'Owner) et ne
     * doit donc pas rester figée dans la langue de la donnée seedée en base.
     */
    private function resolveDescription(array $role): string
    {
        if (($role['slug'] ?? null) === 'owner') {
            return __('role_owner_description');
        }
        return $role['description'] ?? '';
    }

    /** @return array Utilisateurs actifs (tous magasins confondus). */
    private function activeUsers(): array
    {
        return array_values(array_filter(
            $this->users->findAll(),
            fn(array $u): bool => !empty($u['is_active'])
        ));
    }

    /**
     * Employés actifs déjà membres d'au moins un magasin (un rôle store-scope
     * ne peut être accordé que sur un magasin dont l'employé fait déjà
     * partie), enrichis d'un `store_names` affiché en aide dans le formulaire
     * d'ajout.
     */
    private function assignableHolderCandidates(): array
    {
        $out = [];
        foreach ($this->activeUsers() as $user) {
            $memberships = $this->storeUsers->findByUser((int) $user['id']);
            if ($memberships === []) {
                continue;
            }
            $storeNames = [];
            foreach ($memberships as $membership) {
                $store = $this->stores->findById((int) $membership['store_id']);
                if ($store !== null) {
                    $storeNames[] = $store['name'] ?? ('#' . $membership['store_id']);
                }
            }
            $user['store_names'] = implode(', ', $storeNames);
            $out[] = $user;
        }
        return $out;
    }

    /** @return int[] Identifiants utilisateurs cochés dans le formulaire d'ajout de détenteurs. */
    private function postedUserIds(Request $request): array
    {
        $ids = $request->post('user_ids', []);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function findRoleOrFail(Request $request): array
    {
        $role = $this->roles->findById((int) $request->param('id'));
        if ($role === null) {
            throw new NotFoundException(__('error_role_not_found'));
        }
        return $role;
    }

    /** @return string[] Clés de permission cochées dans le formulaire. */
    private function postedPermissions(Request $request): array
    {
        $granted = [];
        foreach ($this->visiblePermissionCategories() as $category => $actions) {
            foreach ($actions as $action) {
                $key = $category . '.' . $action;
                if ($request->post('perm_' . str_replace('.', '_', $key))) {
                    $granted[] = $key;
                }
            }
        }
        return $granted;
    }

    /**
     * Clés de permission cochées "Toutes les boutiques" dans le formulaire —
     * ignore toute case de portée cochée pour une permission qui n'est pas
     * elle-même accordée (défense en profondeur, en plus du grisage JS côté
     * client, voir permission-editor.js).
     * @return string[]
     */
    private function postedGlobalScopeKeys(Request $request): array
    {
        $global = [];
        foreach ($this->visiblePermissionCategories() as $category => $actions) {
            foreach ($actions as $action) {
                $key = $category . '.' . $action;
                $fieldSuffix = str_replace('.', '_', $key);
                if ($request->post('perm_' . $fieldSuffix) && $request->post('scope_' . $fieldSuffix)) {
                    $global[] = $key;
                }
            }
        }
        return $global;
    }

    /**
     * Catégories proposées : Core, bundles actifs déclarants (Bundle::permissions()) et repli historique des bundles
     * actifs non déclarants — voir PermissionCatalog::forActiveBundles().
     */
    private function visiblePermissionCategories(): array
    {
        return PermissionCatalog::forActiveBundles();
    }

    private function isCategoryVisible(string $category): bool
    {
        return array_key_exists($category, $this->visiblePermissionCategories());
    }

    private function roleHolders(int $roleId): array
    {
        $holders = [];
        foreach ($this->assignments->findByRole($roleId) as $assignment) {
            $user = $this->users->findById((int) $assignment['user_id']);
            $scopeLabel = __('scope_global');
            if ($assignment['scope_type'] === 'store' && $assignment['scope_id'] !== null) {
                $store = $this->stores->findById((int) $assignment['scope_id']);
                $scopeLabel = $store['name'] ?? ('#' . $assignment['scope_id']);
            }
            $holders[] = [
                'assignment_id' => (int) $assignment['id'],
                'user_name'     => $user['display_name'] ?? ($user['email'] ?? ('#' . $assignment['user_id'])),
                'initials'      => $this->userInitials($user),
                'color'         => $user['color'] ?? '#6c5ce7',
                'scope_label'   => $scopeLabel,
            ];
        }
        return $holders;
    }

    /** Initiales nom+prénom (repli sur les 2 premiers caractères du nom affiché) — même règle que la colonne "Nom" de /admin/users. */
    private function userInitials(?array $user): string
    {
        $initials = mb_strtoupper(
            mb_substr((string) ($user['last_name'] ?? ''), 0, 1) . mb_substr((string) ($user['first_name'] ?? ''), 0, 1)
        );
        if ($initials === '') {
            $initials = mb_substr(strip_tags((string) ($user['display_name'] ?? '')), 0, 2);
        }
        return $initials;
    }

    private function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    private function uniqueSlug(string $base): string
    {
        if ($base === '') {
            return '';
        }
        $slug = $base;
        $attempt = 1;
        while ($this->roles->findBySlug($slug) !== null) {
            $attempt++;
            $slug = $base . '-' . $attempt;
        }
        return $slug;
    }
}
