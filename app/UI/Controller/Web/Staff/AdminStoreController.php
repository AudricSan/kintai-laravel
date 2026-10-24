<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\Staff;

use kintai\UI\Controller\Web\HasAdminAccess;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Extension\Sales;
use kintai\Core\Extension\StoreFeatures;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Core\Services\StoreServiceInterface;
use kintai\Core\Services\StoreStatsServiceInterface;
use kintai\Core\Routing\RouteSlugService;
use kintai\UI\ViewRenderer;

final class AdminStoreController
{
    use HasAdminAccess;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly AuditLogger $auditLogger,
        private readonly StoreServiceInterface $storeService,
        private readonly StoreStatsServiceInterface $storeStatsService,
        private readonly StoreFeatures $storeFeatures,
        private readonly RoleAssignmentSyncService $roleSync,
        private readonly PermissionService $permissions,
        private readonly ?RouteSlugService $routeSlugs = null,
    ) {}

    /**
     * Cartes de la section « Fonctionnalités » de la fiche magasin : Core et bundles actifs (Bundle::storeFeatures(),
     * contrat 1.3, ou repli historique), déjà triées.
     *
     * @return list<array{key: string, label: string, desc: string, icon: string}>
     */
    private function storeFeatureCards(): array
    {
        return array_map(static fn($f): array => [
            'key'   => $f->key,
            'label' => __($f->label),
            'desc'  => $f->description !== '' ? __($f->description) : '',
            'icon'  => $f->icon,
        ], $this->storeFeatures->available());
    }

    public function stores(Request $request): Response
    {
        $sort   = $request->query('sort') ?? 'name_asc';
        $stores = $this->storeService->getStoresForAdmin($this->managedIds($request), $sort);

        return Response::html($this->view->render('staff.stores', [
            'title'  => 'Stores',
            'stores' => $stores,
            'sort'   => $sort,
        ], 'layout.app'));
    }

    public function createStore(Request $request): Response
    {
        if ($this->managedIds($request) !== null) {
            throw new ForbiddenException(__('error_owner_only_create_store'));
        }

        return Response::html($this->view->render('staff.stores-form', [
            'title'                 => 'Nouveau store',
            'mode'                  => 'create',
            'store'                 => [],
            'featureCards'          => $this->storeFeatureCards(),
        ], 'layout.app'));
    }

    public function storeStore(Request $request): Response
    {
        if ($this->managedIds($request) !== null) {
            throw new ForbiddenException(__('error_owner_only_create_store'));
        }
        // Quota de magasins atteint : retour au formulaire avec le message et la saisie (au lieu d'une page 403 qui
        // perdait tout ce qui avait été tapé).
        try {
            $savedStore = $this->storeService->createStore([
                'code'            => strtoupper(trim($request->post('code', ''))),
                'name'            => $request->post('name', ''),
                'slug'            => (string) $request->post('slug', ''),
                'type'            => ($tmp = $request->post('type', '')) !== '' ? $tmp : 'retail',
                'timezone'        => ($tmp = $request->post('timezone', '')) !== '' ? $tmp : 'UTC',
                'locale'          => ($tmp = $request->post('locale', '')) !== '' ? $tmp : 'en',
                'currency'        => strtoupper(trim(($tmp = $request->post('currency', '')) !== '' ? $tmp : 'EUR')),
                'currency_symbol_style' => $request->post('currency_symbol_style', 'kanji'),
                'phone'           => $request->post('phone', '') ?: null,
                'email'           => $request->post('email', '') ?: null,
                'address_street'  => $request->post('address_street', '') ?: null,
                'address_city'    => $request->post('address_city', '') ?: null,
                'address_postal'  => $request->post('address_postal', '') ?: null,
                'address_country' => $request->post('address_country', '') ?: null,
            ]);
        } catch (PlanLimitExceededException $e) {
            throw new ValidationException(['store' => $e->getMessage()]);
        }

        $this->auditLogger->log($request, 'store.created', 'store', (int) ($savedStore['id'] ?? 0), [
            'name' => $request->post('name', ''),
        ]);
        return Response::redirect($this->base() . '/admin/stores?success=created');
    }

    public function editStore(Request $request): Response
    {
        $store = $this->stores->findById((int) $request->param('id'));
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        $this->assertStoreAccess($request, (int) $store['id']);

        $storeId = (int) $store['id'];
        $roleMap = $this->roleSync->storeRoleMapForStore($storeId);
        $members = array_map(function ($m) use ($roleMap) {
            $name = trim(($m['last_name'] ?? '') . ' ' . ($m['first_name'] ?? ''));
            $assigned = $roleMap[(int) $m['membership']['user_id']] ?? null;
            return array_merge($m['membership'], [
                'user_name'        => $name ?: ($m['email'] ?? '—'),
                'user_email'       => $m['email'] ?? '',
                'role_id'          => $assigned['role_id'] ?? null,
                'role_name'        => $assigned['name'] ?? '—',
                'role_is_managing' => !empty($assigned['is_managing']),
            ]);
        }, $this->storeService->getStoreMembers($storeId));

        $memberUserIds = array_map(fn($m) => (int) $m['user_id'], $members);
        $allUsers = $this->users->findAll();
        $available = array_values(array_filter(
            $allUsers,
            fn($u) => !in_array((int) $u['id'], $memberUserIds, true) && !empty($u['is_active'])
        ));

        return Response::html($this->view->render('staff.stores-form', [
            'title'             => 'Modifier ' . htmlspecialchars($store['name'] ?? ''),
            'mode'              => 'edit',
            'store'             => $store,
            'members'           => $members,
            'available'         => $available,
            'assignable_roles'  => $this->roleSync->assignableStoreRoles(),
            'default_role_id'   => (int) ($this->roleSync->defaultStoreRole()['id'] ?? 0),
            'deductionSettings' => $this->storeService->getDeductionSettings($storeId),
            // Aucune ligne en base = jamais configuré → toutes les fonctionnalités actives par défaut (null)
            'enabledFeatures'   => ($_ef = $this->stores->getFeatures($storeId)) !== [] ? $_ef : null,
            'importSettings'    => $this->stores->getImportSettings($storeId),
            'routeSlug'         => $this->routeSlugs?->currentStoreSlug($storeId),
            'featureCards'          => $this->storeFeatureCards(),
        ], 'layout.app'));
    }

    public function updateStore(Request $request): Response
    {
        $store = $this->stores->findById((int) $request->param('id'));
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        $storeId = (int) $store['id'];
        $this->assertStoreAccess($request, $storeId);

        // Une décision par case proposée (Core et bundles actifs) ; une fonctionnalité jamais réglée est active. Celle
        // d'un bundle désactivé n'est pas proposée et garde sa décision (saveFeatureDecisions ne touche qu'à celles-ci).
        $decisions = $this->stores->getFeatureDecisions($storeId);
        $enabledFeatures = [];
        foreach ($this->storeFeatures->availableKeys() as $slug) {
            $enabledFeatures[$slug] = $request->postFlag('feature_' . $slug, ($decisions[$slug] ?? true) ? 1 : 0) === 1;
        }

        // Champ absent de la requête : valeur actuelle conservée (audit du 13/10/2026, M12). Avant, une requête
        // partielle désactivait le magasin (is_active absent), vidait ses coordonnées et remettait ses réglages
        // d'import et de retenues aux valeurs par défaut.
        $keep   = fn(string $field, mixed $current): mixed => $request->hasPost($field) ? $request->post($field) : $current;
        $text   = fn(string $field): ?string => $request->hasPost($field) ? (trim((string) $request->post($field, '')) ?: null) : ($store[$field] ?? null);
        $import = $this->stores->getImportSettings($storeId);
        $ded    = $this->stores->getDeductionSettings($storeId);

        $data = [
            'code'                  => strtoupper(trim((string) $keep('code', $store['code'] ?? ''))),
            'name'                  => $keep('name', $store['name'] ?? ''),
            // null = champ absent : le slug manuel existant est conservé (voir StoreService).
            'slug'                  => $request->hasPost('slug') ? (string) $request->post('slug', '') : null,
            'type'                  => ($tmp = (string) $request->post('type', '')) !== '' ? $tmp : ($store['type'] ?? 'retail'),
            'timezone'              => ($tmp = (string) $request->post('timezone', '')) !== '' ? $tmp : ($store['timezone'] ?? 'UTC'),
            'locale'                => ($tmp = (string) $request->post('locale', '')) !== '' ? $tmp : ($store['locale'] ?? 'en'),
            'currency'              => strtoupper(trim(($tmp = (string) $request->post('currency', '')) !== '' ? $tmp : ($store['currency'] ?? 'EUR'))),
            'currency_symbol_style' => $keep('currency_symbol_style', $store['currency_symbol_style'] ?? 'kanji'),
            'phone'                 => $text('phone'),
            'email'                 => $text('email'),
            'address_street'        => $text('address_street'),
            'address_city'          => $text('address_city'),
            'address_postal'        => $text('address_postal'),
            'address_country'       => $text('address_country'),
            'week_start_day'        => max(1, min(7, (int) $keep('week_start_day', $store['week_start_day'] ?? 1))),
            'min_shift_minutes'     => (int) $keep('min_shift_minutes', $store['min_shift_minutes'] ?? 120),
            'max_shift_minutes'     => (int) $keep('max_shift_minutes', $store['max_shift_minutes'] ?? 480),
            'min_staff_per_day'     => (int) $keep('min_staff_per_day', $store['min_staff_per_day'] ?? 0),
            'low_staff_hour_start'  => max(-1, min(23, (int) $keep('low_staff_hour_start', $store['low_staff_hour_start'] ?? -1))),
            'low_staff_hour_end'    => max(-1, min(23, (int) $keep('low_staff_hour_end', $store['low_staff_hour_end'] ?? -1))),
            'is_active'             => $request->postFlag('is_active', $store['is_active'] ?? 1),
            '_excel_settings'       => [
                'col_start'                => (int)    $keep('excel_col_start',            $import['col_start'] ?? 4),
                'col_end'                  => (int)    $keep('excel_col_end',              $import['col_end'] ?? 52),
                'base_hour'                => (int)    $keep('excel_base_hour',            $import['base_hour'] ?? 6),
                'minutes_per_col'          => (int)    $keep('excel_minutes_per_col',      $import['minutes_per_col'] ?? 30),
                'block_size'               => (int)    $keep('excel_block_size',           $import['block_size'] ?? 9),
                'shift_rows'               => (int)    $keep('excel_shift_rows',           $import['shift_rows'] ?? 7),
                'sheet_filter_pattern'     => (string) $keep('excel_sheet_filter_pattern', $import['sheet_filter_pattern'] ?? '^\d{4}$'),
                'auto_pause_after_minutes' => (int)    $keep('auto_pause_after_minutes',   $import['auto_pause_after_minutes'] ?? 0),
                'auto_pause_minutes'       => (int)    $keep('auto_pause_minutes',         $import['auto_pause_minutes'] ?? 30),
            ],
            '_deduction_settings'   => [
                'enabled'                   => $request->postFlag('ded_enabled', $ded['enabled'] ?? 0) === 1,
                'health_insurance_rate'     => (float) $keep('ded_health_rate',     $ded['health_insurance_rate'] ?? 0),
                'pension_rate'              => (float) $keep('ded_pension_rate',    $ded['pension_rate'] ?? 0),
                'employment_insurance_rate' => (float) $keep('ded_employment_rate', $ded['employment_insurance_rate'] ?? 0),
                'income_tax_rate'           => (float) $keep('ded_income_tax_rate', $ded['income_tax_rate'] ?? 0),
                'resident_tax_monthly'      => (float) $keep('ded_resident_tax',    $ded['resident_tax_monthly'] ?? 0),
            ],
            '_features'             => $enabledFeatures,
        ];

        $newStoreData = $this->storeService->updateStore($storeId, $data);

        $this->auditLogger->logUpdate($request, 'store.updated', 'store', $storeId, $store, $newStoreData, [
            'name' => $request->post('name', $store['name'] ?? ''),
        ], $storeId);
        return Response::redirect($this->base() . '/admin/stores?success=updated');
    }

    public function deleteStore(Request $request): Response
    {
        if ($this->managedIds($request) !== null) {
            throw new ForbiddenException(__('error_owner_only_delete_store'));
        }

        $id    = (int) $request->param('id');
        $store = $this->stores->findById($id);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        $this->storeService->deleteStore($id);
        $this->auditLogger->log($request, 'store.deleted', 'store', $id, [
            'name' => $store['name'] ?? null,
        ]);
        return Response::redirect($this->base() . '/admin/stores?success=deleted');
    }

    /**
     * Le compte ciblé doit exister, et seul un Owner peut agir sur un compte Owner
     * (même règle que les fiches employés : sinon un gérant pouvait toucher à l'Owner).
     */
    private function ownerGuard(): OwnerGuard
    {
        return new OwnerGuard($this->permissions, $this->users);
    }

    private function callerId(Request $request): int
    {
        return (int) ($request->getAttribute('auth_user')['id'] ?? 0);
    }

    private function assertCanManageMember(Request $request, int $userId): void
    {
        if ($this->users->findById($userId) === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }
        $callerId = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        $owners   = $this->permissions->ownerUserIds();
        if ($userId !== $callerId && in_array($userId, $owners, true) && !in_array($callerId, $owners, true)) {
            throw new ForbiddenException(__('error_not_store_manager'));
        }
    }

    /** Un non-Owner ne peut attribuer qu'un rôle plafonné par ses propres droits (voir PermissionService::canAssignRole). */
    private function assertCanAssignRole(Request $request, int $roleId, int $storeId): void
    {
        if (!$this->permissions->canAssignRole($request->getAttribute('auth_user') ?? [], $roleId, $storeId)) {
            throw new ForbiddenException(__('error_role_exceeds_own_permissions'));
        }
    }

    public function addMember(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $store   = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }
        $this->assertStoreAccess($request, $storeId);

        $userId = (int) $request->post('user_id', 0);
        if ($userId > 0) {
            $this->assertCanManageMember($request, $userId);
        }
        // Rôle dynamique (roles en base) — repli sur le rôle par défaut si
        // l'id posté est absent/invalide, pour rester tolérant aux anciens formulaires.
        $role = $this->roleSync->findAssignableRole((int) $request->post('role_id', 0))
            ?? $this->roleSync->defaultStoreRole();

        if ($userId > 0 && $role !== null) {
            $roleId = (int) $role['id'];
            $this->assertCanAssignRole($request, $roleId, $storeId);
            $membership = $this->storeService->addMember($storeId, $userId);
            if ($membership !== null) {
                $this->roleSync->syncStoreRoleById($userId, $storeId, $roleId);
            }
            $this->auditLogger->log($request, 'store.member_added', 'store_user', null, [
                'store_id' => $storeId,
                'user_id'  => $userId,
                'role_id'  => $roleId,
                'role'     => $role['name'] ?? null,
            ], $storeId);
        }

        // Retour vers la fiche employé quand l'ajout part de là (champ caché), sinon vers la fiche du magasin.
        $dest = safe_redirect_path(
            (string) $request->post('redirect_to', ''),
            $this->base() . '/admin/stores/' . store_segment($storeId) . '/edit?success=member_added',
        );
        return Response::redirect($dest);
    }

    public function updateMemberRole(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $mid     = (int) $request->param('mid');

        $membership = $this->storeUsers->findById($mid);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_membership_not_found'));
        }
        $this->assertStoreAccess($request, $storeId);

        $role = $this->roleSync->findAssignableRole((int) $request->post('role_id', 0));
        if ($role === null) {
            return Response::redirect($this->base() . '/admin/stores/' . store_segment($storeId) . '/edit?error=invalid_role');
        }
        $roleId  = (int) $role['id'];
        $userId  = (int) $membership['user_id'];
        $this->assertCanManageMember($request, $userId);
        $this->ownerGuard()->assertNotSelfUnlessOwner($this->callerId($request), $userId);
        $this->assertCanAssignRole($request, $roleId, $storeId);
        $oldRole = $this->roleSync->storeRoleMapForStore($storeId)[$userId]['name'] ?? '—';

        $this->roleSync->syncStoreRoleById($userId, $storeId, $roleId);
        $this->auditLogger->logUpdate($request, 'store.member_role_updated', 'store_user', $mid, ['role' => $oldRole], ['role' => $role['name'] ?? null, 'role_id' => $roleId], [
            'store_id' => $storeId,
            'user_id' => $membership['user_id'] ?? null,
        ], $storeId);

        return Response::redirect($this->base() . '/admin/stores/' . store_segment($storeId) . '/edit?success=role_updated');
    }

    public function removeMember(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $mid     = (int) $request->param('mid');

        $membership = $this->storeUsers->findById($mid);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_membership_not_found'));
        }
        $this->assertStoreAccess($request, $storeId);
        $this->assertCanManageMember($request, (int) $membership['user_id']);
        $this->ownerGuard()->assertNotSelfUnlessOwner($this->callerId($request), (int) $membership['user_id']);

        $this->storeService->removeMember($mid, $storeId);
        $this->roleSync->revokeStoreRole((int) $membership['user_id'], $storeId);
        $this->auditLogger->log($request, 'store.member_removed', 'store_user', $mid, [
            'store_id' => $storeId,
            'user_id' => $membership['user_id'] ?? null,
        ], $storeId);

        return Response::redirect($this->base() . '/admin/stores/' . store_segment($storeId) . '/edit?success=member_removed');
    }

    public function editMemberDeductions(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $mid     = (int) $request->param('mid');
        $this->assertStoreAccess($request, $storeId);

        $store = $this->stores->findById($storeId);
        if ($store === null) throw new NotFoundException(__('error_store_not_found'));

        $membership = $this->storeUsers->findById($mid);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }

        $user = $this->users->findById((int) $membership['user_id']);
        if ($user === null) throw new NotFoundException(__('error_user_not_found'));

        return Response::html($this->view->render('staff.member-deductions', [
            'title'              => 'Cotisations — ' . ($user['display_name'] ?? $user['email'] ?? ''),
            'store'              => $store,
            'membership'         => $membership,
            'user'               => $user,
            'deductionSettings'  => $this->storeService->getDeductionSettings($storeId),
            'deductionOverrides' => $this->storeService->getDeductionOverrides($mid),
        ], 'layout.app'));
    }

    public function saveMemberDeductions(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $mid     = (int) $request->param('mid');
        $this->assertStoreAccess($request, $storeId);

        $membership = $this->storeUsers->findById($mid);
        if ($membership === null || (int) $membership['store_id'] !== $storeId) {
            throw new NotFoundException(__('error_member_not_found'));
        }

        $overrides    = $this->storeService->getDeductionOverrides($mid);
        $oldSubjectTo = $overrides['subject_to_deductions'] ?? false;
        // Champ absent : réglage actuel conservé (audit M12) ; la case envoie toujours 0 ou 1 (champ caché).
        $newSubjectTo = $request->postFlag('subject_to_deductions', $oldSubjectTo) === 1;
        $this->storeService->saveMemberDeductions($mid, $storeId, $newSubjectTo);
        $this->auditLogger->logUpdate($request, 'store.member_deductions_updated', 'store_user', $mid, ['subject_to_deductions' => $oldSubjectTo], ['subject_to_deductions' => $newSubjectTo], [
            'store_id' => $storeId,
            'user_id'  => $membership['user_id'] ?? null,
        ], $storeId);

        $redirectTo = $request->post('_redirect_to', '');
        $fallback   = $this->base() . '/admin/stores/' . store_segment($storeId) . '/edit?success=deductions_saved';
        $target     = ($redirectTo !== '' && str_starts_with($redirectTo, $this->base() . '/admin/'))
            ? $redirectTo
            : $fallback;

        return Response::redirect($target);
    }

    public function storeStats(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $this->assertStoreAccess($request, $storeId);

        $store = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        $period      = max(7, min(365, (int) ($request->query('period') ?? 30)));
        $minShiftMin = (int) ($store['min_shift_minutes'] ?? 0);
        $maxShiftMin = (int) ($store['max_shift_minutes'] ?? 0);

        $stats = $this->storeStatsService->storeStats($storeId, $period, $minShiftMin, $maxShiftMin);

        return Response::html($this->view->render('analytics.store-stats', array_merge($stats, [
            'title'        => 'Statistiques — ' . ($store['name'] ?? ''),
            'store'        => $store,
            'currency'     => $store['currency'] ?? 'EUR',
            'minShiftMin'  => $minShiftMin,
            'maxShiftMin'  => $maxShiftMin,
        ]), 'layout.app'));
    }

    public function storeStatsExport(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $this->assertStoreAccess($request, $storeId);

        $store = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        $period  = max(7, min(365, (int) ($request->query('period') ?? 30)));
        $data    = $this->storeStatsService->storeStatsExportData($storeId, $period);
        $since   = $data['since'];
        $today   = $data['today'];
        $n       = $data['n'];
        $name    = $store['name'] ?? 'store';
        $currency = currency_symbol($store['currency'] ?? 'EUR', store_currency_style($store));

        $buf = fopen('php://memory', 'r+');
        fprintf($buf, "\xEF\xBB\xBF");
        $put = fn(array $row) => fputcsv($buf, $row, ';');

        $put(['# Kintai — Export statistiques']);
        $put(['Magasin', $name]);
        $put(['Période', "{$since} → {$today} ({$period} jours)"]);
        $put([]);

        $put(['=== Résumé ===']);
        $put(['Shifts analysés',       $n]);
        $put(['Heures nettes',         number_format($data['totalNetHours'],   1, '.', '')]);
        $put(['Heures brutes',         number_format($data['totalGrossHours'], 1, '.', '')]);
        $put(['Durée moy. shift (h)',  number_format($data['avgDuration'],     2, '.', '')]);
        $put(['Jours actifs',          $data['activeDays']]);
        $put(['Taux modification (%)', $data['modRate']]);
        $put(['Score équité',          $data['equityScore']]);
        $put(['Score efficacité',      $data['efficiencyScore']]);
        $put(['Score stabilité',       $data['stabilityScore']]);
        $put([]);

        $put(['=== Évolution hebdomadaire ===']);
        $put(['Semaine', 'Heures']);
        foreach ($data['hoursByWeek'] as $week => $h) {
            $put([$week, number_format($h, 1, '.', '')]);
        }
        $put([]);

        $put(['=== Évolution mensuelle ===']);
        $put(['Mois', 'Heures nettes', "Coût ({$currency})"]);
        $months = array_unique(array_merge(array_keys($data['hoursByMonth']), array_keys($data['costByMonth'])));
        sort($months);
        foreach ($months as $month) {
            $put([$month,
                number_format($data['hoursByMonth'][$month] ?? 0, 1, '.', ''),
                number_format($data['costByMonth'][$month]  ?? 0, 2, '.', ''),
            ]);
        }
        $put([]);

        $put(['=== Heures par employé ===']);
        $put(['Employé', 'Heures', "Coût ({$currency})"]);
        foreach ($data['hoursByUser'] as $uid => $h) {
            $u   = $data['usersMap'][$uid] ?? [];
            $emp = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '')) ?: ($u['email'] ?? '#' . $uid);
            $put([$emp, number_format($h, 1, '.', ''), number_format($data['costByUser'][$uid] ?? 0, 2, '.', '')]);
        }
        $put([]);

        $put(['=== Heures par type de shift ===']);
        $put(['Type', 'Heures']);
        foreach ($data['hoursByType'] as $label => $h) {
            $put([$label, number_format($h, 1, '.', '')]);
        }

        rewind($buf);
        $csv = stream_get_contents($buf);
        fclose($buf);

        $this->auditLogger->log($request, 'export.store_stats', 'store', $storeId, [
            'period'  => $period,
            'format'  => 'csv',
        ], $storeId);

        $filename = 'stats-' . preg_replace('/[^a-z0-9]+/i', '-', $name) . '-' . $since . '.csv';
        return Response::csv($csv, $filename);
    }

    public function storeProfitability(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $this->assertStoreAccess($request, $storeId);
        // Sans fournisseur de ventes actif (daily-report), la page n'a rien à montrer : elle n'est pas proposée.
        if (!Sales::current()->available()) {
            throw new NotFoundException(__('error_404_message'));
        }

        $store = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        $today  = date('Y-m-d');
        $preset = (int) ($request->query('preset') ?? 90);
        $preset = in_array($preset, [7, 30, 90, 180, 365], true) ? $preset : 90;
        $from   = $request->query('from') ?: date('Y-m-d', strtotime("-{$preset} days"));
        $to     = $request->query('to')   ?: $today;
        if ($from > $to) [$from, $to] = [$to, $from];

        $data     = $this->storeStatsService->storeProfitability($storeId, $from, $to);
        $currency = currency_symbol($store['currency'] ?? 'EUR', store_currency_style($store));

        return Response::html($this->view->render('analytics.store-profitability', array_merge($data, [
            'title'    => 'Rentabilité — ' . ($store['name'] ?? ''),
            'store'    => $store,
            'currency' => $currency,
            'from'     => $from,
            'to'       => $to,
            'preset'   => $preset,
            // Lien vers les rapports journaliers du magasin, si le bundle qui les fournit est là.
            'daily_reports_url' => \kintai\Core\Container::getInstance()->make(\kintai\Core\Router::class)->routeByName('admin.daily_reports.index') !== null
                ? $this->base() . '/admin/stores/' . $storeId . '/daily-reports' : null,
        ]), 'layout.app'));
    }

    public function employeeStats(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $userId  = (int) $request->param('uid');
        $this->assertStoreAccess($request, $storeId);

        $store = $this->stores->findById($storeId);
        if ($store === null) throw new NotFoundException(__('error_store_not_found'));

        $user = $this->users->findById($userId);
        if ($user === null) throw new NotFoundException(__('error_employee_not_found'));

        $membership = $this->storeUsers->findMembership($storeId, $userId);
        if ($membership === null) throw new ForbiddenException(__('error_employee_not_store_member'));
        $roleEntry = $this->roleSync->storeRoleMapForUser($userId)[$storeId] ?? null;
        $membership['role_name']        = $roleEntry['name'] ?? '—';
        $membership['role_is_managing'] = !empty($roleEntry['is_managing']);

        $period = max(7, min(365, (int) ($request->query('period') ?? 30)));
        $data   = $this->storeStatsService->employeeStats($storeId, $userId, $period);

        $this->auditLogger->log($request, 'employee_stats.viewed', 'user', $userId, [
            'store_id' => $storeId, 'period' => $period,
        ], $storeId);

        $empName        = trim(($user['last_name'] ?? '') . ' ' . ($user['first_name'] ?? '')) ?: ($user['email'] ?? '');

        return Response::html($this->view->render('staff.employee-stats', array_merge($data, [
            'title'           => 'Statistiques — ' . $empName,
            'store'           => $store,
            'user'            => $user,
            'membership'      => $membership,
            'currency'        => $store['currency'] ?? 'EUR',
        ]), 'layout.app'));
    }

    public function employeeReport(Request $request): Response
    {
        $storeId = (int) $request->param('id');
        $this->assertStoreAccess($request, $storeId);

        $store = $this->stores->findById($storeId);
        if ($store === null) {
            throw new NotFoundException(__('error_store_not_found'));
        }

        $period = max(7, min(365, (int) ($request->query('period') ?? 30)));
        $data   = $this->storeStatsService->employeeReport($storeId, $period);

        return Response::html($this->view->render('staff.employee-report', array_merge($data, [
            'title'    => 'Rapport employés — ' . ($store['name'] ?? ''),
            'store'    => $store,
            'currency' => $store['currency'] ?? 'EUR',
        ]), 'layout.app'));
    }

}
