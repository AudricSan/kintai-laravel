<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\Staff;

use kintai\Contract\Event\EmployeeCreated;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Extension\Events;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\UserDisplayName;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\EmployeeStatsService;
use kintai\Core\Services\PdfCjkFontResolver;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Core\Validation\ColorRule;
use kintai\UI\ViewRenderer;
use kintai\UI\Controller\Web\HasAdminAccess;
use kintai\Core\Routing\EmployeeRouteBinder;

final class AdminUserController
{
    use HasAdminAccess;
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly UserShiftTypeRateRepositoryInterface $userRates,
        private readonly AuditLogger $auditLogger,
        private readonly RoleAssignmentSyncService $roleSync,
        private readonly PermissionService $permissions,
        private readonly PlanLimitService $planLimits,
        private readonly OwnerGuard $ownerGuard,
        // Optionnel pour les tests qui construisent le contrôleur à la main.
        private readonly ?CredentialRevoker $revoker = null,
        private readonly ?UserAnonymizer $anonymizer = null,
        private readonly ?Events $events = null,
    ) {}

    /** Annonce des événements aux bundles (contrat 1.13) : celle de la requête courante, sauf si un test en fournit une. */
    private function events(): Events
    {
        return $this->events ?? Events::current();
    }

    /** Le conteneur l'injecte toujours ; un test qui supprime un compte doit le fournir. */
    private function anonymizer(): UserAnonymizer
    {
        return $this->anonymizer ?? throw new \LogicException('UserAnonymizer requis pour supprimer un compte.');
    }

    /**
     * Garde d'accès à l'employé ciblé par une route /admin/users/{id}/... : la permission doit être
     * accordée sur l'un de ses magasins (managed_store_ids ne dit que « quelque part »), et seul un
     * Owner peut agir sur un compte Owner — sans quoi un gérant pouvait réinitialiser le mot de passe
     * de l'Owner ou modifier un employé d'un autre magasin.
     */
    private function assertCanActOnUser(Request $request, int $userId, string $permissionKey): void
    {
        $authUser = $request->getAttribute('auth_user') ?? [];
        // Owner (is_admin est dérivé du rôle système par AuthService) : aucune restriction.
        if (!empty($authUser['is_admin'])) {
            return;
        }
        $storeIds = array_map(fn(array $m): int => (int) $m['store_id'], $this->storeUsers->findByUser($userId));
        $this->permissions->requireUserAccess($authUser, $permissionKey, $userId, $storeIds);
    }

    /**
     * Enrichit chaque ligne d'un flag `is_admin` calculé depuis le RBAC
     * dynamique (Owner = affectation globale sur le rôle système), en
     * remplacement de la colonne historique users.is_admin.
     */
    private function enrichIsAdmin(array $users): array
    {
        $ownerIds = $this->permissions->ownerUserIds();
        foreach ($users as &$u) {
            $u['is_admin'] = in_array((int) $u['id'], $ownerIds, true) ? 1 : 0;
        }
        unset($u);
        return $users;
    }

    // -------------------------------------------------------------------------
    // Users — CRUD
    // -------------------------------------------------------------------------

    public function users(Request $request): Response
    {
        $managedIds = $this->managedIds($request);

        if ($managedIds !== null) {
            $memberIds = $this->memberUserIds($managedIds);
            $users = array_values(array_filter(
                $this->users->findAll(),
                fn($u) => in_array((int) $u['id'], $memberIds, true)
            ));
        } else {
            $users = $this->users->findAll();
        }
        // Comptes supprimés (anonymisés) : conservés pour l'historique, plus listés.
        $users = array_values(array_filter($users, fn(array $u): bool => empty($u['deleted_at'])));
        $users = $this->enrichIsAdmin($users);

        // Statistiques du mois en cours — même source que le dashboard employé et le
        // rapport de salaire (ShiftWageCalculator::costOf() via EmployeeStatsService),
        // pour que ce montant "Salaire est." ne diverge plus des autres pages.
        $employeeStatsService = new EmployeeStatsService(
            $this->shifts,
            $this->shiftTypes,
            $this->userRates,
            $this->storeUsers,
            $this->stores,
        );

        $userStats = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $stats = $employeeStatsService->calculate($uid);
            $userStats[$uid] = [
                'hours_month'   => $stats['hours_month'],
                'hours_week'    => $stats['hours_week'],
                'estimated_pay' => $stats['estimated_pay'],
            ];
        }

        $availableStores = $this->availableStores($managedIds);
        $storeCurrency   = strtoupper(trim($availableStores[0]['currency'] ?? 'JPY'));
        $storeCurrencyStyle = store_currency_style($availableStores[0] ?? null);

        // Map store id → name
        $storeNames = [];
        $availableStoreIds = [];
        foreach ($availableStores as $s) {
            $sid = (int) $s['id'];
            $storeNames[$sid] = $s['name'];
            $availableStoreIds[] = $sid;
        }

        // Pour chaque user : liste des storeIds et premier store (liens stats/paie)
        $userStoreIds = [];
        $userStoreMap = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $ids = [];
            foreach ($this->storeUsers->findByUser($uid) as $m) {
                $sid = (int) $m['store_id'];
                if (in_array($sid, $availableStoreIds, true)) {
                    $ids[] = $sid;
                    $userStoreMap[$uid] ??= $sid;
                }
            }
            $userStoreIds[$uid] = $ids;
        }

        // Filtre par magasin
        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        if ($filterStoreId > 0) {
            $users = array_values(array_filter(
                $users,
                fn($u) => in_array($filterStoreId, $userStoreIds[(int) $u['id']] ?? [], true)
            ));
        } elseif ($filterStoreId === -1) {
            $users = array_values(array_filter(
                $users,
                fn($u) => empty($userStoreIds[(int) $u['id']])
            ));
        }

        // Recherche texte (nom, code employé, email) — appliquée entièrement
        // côté client (voir kana-search.js/users-list-search.js) pour un
        // filtrage instantané, tolérant à la langue (romaji/hiragana/kana
        // vers un nom stocké en kanji, via les furigana_*) et sans
        // rechargement de page à chaque frappe. `$search` ne sert ici qu'à
        // pré-remplir le champ pour un lien direct (`?search=...`).
        $search = trim((string) ($request->query('search') ?? ''));

        // Tri
        $sort = $request->query('sort') ?? 'name_asc';
        usort($users, function ($a, $b) use ($sort, $userStats) {
            $nameA   = strtolower($a['display_name'] ?? $a['email'] ?? '');
            $nameB   = strtolower($b['display_name'] ?? $b['email'] ?? '');
            $emailA  = strtolower($a['email'] ?? '');
            $emailB  = strtolower($b['email'] ?? '');
            $roleA   = (int) ($a['is_admin'] ?? 0);
            $roleB   = (int) ($b['is_admin'] ?? 0);
            $statA   = (int) (!empty($a['is_active']) && empty($a['deleted_at']));
            $statB   = (int) (!empty($b['is_active']) && empty($b['deleted_at']));
            $hA      = $userStats[(int)($a['id'] ?? 0)]['hours_month'] ?? 0;
            $hB      = $userStats[(int)($b['id'] ?? 0)]['hours_month'] ?? 0;
            return match ($sort) {
                'name_desc'   => strcmp($nameB, $nameA),
                'email_asc'   => strcmp($emailA, $emailB) ?: strcmp($nameA, $nameB),
                'email_desc'  => strcmp($emailB, $emailA) ?: strcmp($nameA, $nameB),
                'role_asc'    => $roleA <=> $roleB ?: strcmp($nameA, $nameB),
                'role_desc'   => $roleB <=> $roleA ?: strcmp($nameA, $nameB),
                'status_asc'  => $statA <=> $statB ?: strcmp($nameA, $nameB),
                'status_desc' => $statB <=> $statA ?: strcmp($nameA, $nameB),
                'hours_asc'   => $hA <=> $hB ?: strcmp($nameA, $nameB),
                'hours_desc'  => $hB <=> $hA ?: strcmp($nameA, $nameB),
                default       => strcmp($nameA, $nameB), // name_asc
            };
        });

        return Response::html($this->view->render('staff.users', [
            'title'            => 'Personnel',
            'users'            => $users,
            'user_stats'       => $userStats,
            'month_label'      => month_label(date('Y-m')),
            'store_currency'   => $storeCurrency,
            'store_currency_symbol_style' => $storeCurrencyStyle,
            'sort'             => $sort,
            'available_stores' => $availableStores,
            'store_names'      => $storeNames,
            'user_store_ids'   => $userStoreIds,
            'user_store_map'   => $userStoreMap,
            'filter_store_id'  => $filterStoreId,
            'filter_search'    => $search,
        ], 'layout.app'));
    }

    // -------------------------------------------------------------------------
    // Users — export (item 3)
    // -------------------------------------------------------------------------

    public function exportUsersJson(Request $request): Response
    {
        $rows = $this->usersForExport($request);

        $this->auditLogger->log($request, 'export.employees_json', 'user', 0, ['count' => count($rows)]);

        return Response::jsonDownload(['data' => $rows], 'employees_' . date('Ymd') . '.json');
    }

    /**
     * Aperçu HTML du PDF (route .../export/pdf) : pas de téléchargement
     * automatique, la même vue users-export-pdf.php est rendue directement
     * dans le navigateur avec une barre d'outils (impression / téléchargement
     * réel / fermer), comme les rapports RH. Le fichier PDF n'est généré
     * (via mPDF) que si l'utilisateur clique "Télécharger" (exportUsersPdfDownload()).
     */
    public function exportUsersPdf(Request $request): Response
    {
        $rows = $this->usersForExport($request);

        $storeId = (int) ($request->query('store_id') ?? 0);
        $downloadUrl = $this->base() . '/admin/users/export/pdf/download' . ($storeId !== 0 ? '?store_id=' . $storeId : '');

        $html = $this->view->render('staff.users-export-pdf', [
            'rows'         => $rows,
            'generated_at' => date('Y-m-d H:i'),
            'downloadUrl'  => $downloadUrl,
        ]);

        return Response::html($html);
    }

    public function exportUsersPdfDownload(Request $request): Response
    {
        $rows = $this->usersForExport($request);

        $html = $this->view->render('staff.users-export-pdf', [
            'rows'         => $rows,
            'generated_at' => date('Y-m-d H:i'),
        ]);

        $tmpDir = storage_path('app/mpdf');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $mpdf = new \Mpdf\Mpdf(PdfCjkFontResolver::applyTo([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 12,
            'margin_bottom' => 12,
            'tempDir'       => $tmpDir,
        ]));
        $mpdf->SetTitle('Employés');
        $mpdf->WriteHTML($html);

        $this->auditLogger->log($request, 'export.employees_pdf', 'user', 0, ['count' => count($rows)]);

        return Response::pdf(
            $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
            'employees_' . date('Ymd') . '.pdf',
        );
    }

    /**
     * Liste des employés visibles (mêmes filtres store_id que la page), avec
     * coordonnées et taux horaires — pour les exports PDF/JSON.
     */
    private function usersForExport(Request $request): array
    {
        $managedIds = $this->managedIds($request);

        if ($managedIds !== null) {
            $memberIds = $this->memberUserIds($managedIds);
            $users = array_values(array_filter(
                $this->users->findAll(),
                fn($u) => in_array((int) $u['id'], $memberIds, true)
            ));
        } else {
            $users = $this->users->findAll();
        }
        // Comptes supprimés (anonymisés) : conservés pour l'historique, plus listés.
        $users = array_values(array_filter($users, fn(array $u): bool => empty($u['deleted_at'])));
        $users = $this->enrichIsAdmin($users);

        $availableStores = $this->availableStores($managedIds);
        $availableStoreIds = array_map(fn($s) => (int) $s['id'], $availableStores);
        $storeNames = [];
        foreach ($availableStores as $s) {
            $storeNames[(int) $s['id']] = $s['name'] ?? '';
        }

        $userStoreIds = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $ids = [];
            foreach ($this->storeUsers->findByUser($uid) as $m) {
                $sid = (int) $m['store_id'];
                if (in_array($sid, $availableStoreIds, true)) {
                    $ids[] = $sid;
                }
            }
            $userStoreIds[$uid] = $ids;
        }

        $filterStoreId = (int) ($request->query('store_id') ?? 0);
        if ($filterStoreId > 0) {
            $users = array_values(array_filter(
                $users,
                fn($u) => in_array($filterStoreId, $userStoreIds[(int) $u['id']] ?? [], true)
            ));
        } elseif ($filterStoreId === -1) {
            $users = array_values(array_filter(
                $users,
                fn($u) => empty($userStoreIds[(int) $u['id']])
            ));
        }

        usort($users, fn($a, $b) => strcmp(
            strtolower($a['display_name'] ?? $a['email'] ?? ''),
            strtolower($b['display_name'] ?? $b['email'] ?? ''),
        ));

        $shiftTypeNames = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $shiftTypeNames[(int) $t['id']] = $t['name'] ?? ('#' . $t['id']);
        }

        $rows = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];

            $rates = [];
            foreach ($this->userRates->findByUser($uid) as $r) {
                $tid = (int) $r['shift_type_id'];
                $rates[] = [
                    'shift_type'  => $shiftTypeNames[$tid] ?? ('#' . $tid),
                    'hourly_rate' => (float) $r['hourly_rate'],
                ];
            }

            $storeLabels = array_values(array_filter(array_map(
                fn($sid) => $storeNames[$sid] ?? null,
                $userStoreIds[$uid] ?? [],
            )));

            $rows[] = [
                'id'            => $uid,
                'employee_code' => $u['employee_code'] ?? null,
                'last_name'     => $u['last_name'] ?? '',
                'first_name'    => $u['first_name'] ?? '',
                'display_name'  => $u['display_name'] ?? '',
                'email'         => $u['email'] ?? '',
                'phone'         => $u['phone'] ?? null,
                'mobile_phone'  => $u['mobile_phone'] ?? null,
                'postal_code'   => $u['postal_code'] ?? null,
                'address'       => $u['address'] ?? null,
                'stores'        => $storeLabels,
                'is_admin'      => !empty($u['is_admin']),
                'is_active'     => !empty($u['is_active']) && empty($u['deleted_at']),
                'hourly_rates'  => $rates,
            ];
        }

        return $rows;
    }

    public function createUser(Request $request): Response
    {
        return Response::html($this->view->render('staff.users-form', [
            'title'                 => 'Nouvel utilisateur',
            'mode'                  => 'create',
            'user'                  => [],
            'all_stores'            => $this->availableStores($this->managedIds($request)),
            'all_roles'             => $this->roleSync->allRoles(),
            'default_store_role_id' => (int) ($this->roleSync->defaultStoreRole()['id'] ?? 0),
        ], 'layout.app'));
    }

    public function storeUser(Request $request): Response
    {
        ColorRule::assert($request->post('color', '#3B82F6'));
        try {
            $this->planLimits->assertCanCreateEmployee();
        } catch (PlanLimitExceededException) {
            return Response::redirect($this->base() . '/admin/users/create?error=plan_limit_employees');
        }

        $email = trim($request->post('email', ''));
        if ($email !== '' && $this->users->findByEmail($email) !== null) {
            return Response::redirect($this->base() . '/admin/users/create?error=email_taken');
        }

        if (trim($request->post('last_name', '')) === '' || trim($request->post('first_name', '')) === '') {
            return Response::redirect($this->base() . '/admin/users/create?error=name_required');
        }

        if (trim($request->post('furigana_last_name', '')) === '' || trim($request->post('furigana_first_name', '')) === '') {
            return Response::redirect($this->base() . '/admin/users/create?error=furigana_required');
        }

        $password = $request->post('password', '');
        $empCode  = strtoupper(trim($request->post('employee_code', ''))) ?: null;
        if ($empCode !== null && !EmployeeRouteBinder::isValidCode($empCode)) {
            return Response::redirect($this->base() . '/admin/users/create?error=employee_code_invalid_url');
        }
        // Code déjà pris : sans ce contrôle, la contrainte unique de la base donnait une erreur 500.
        if ($empCode !== null && $this->users->findByEmployeeCode($empCode) !== null) {
            return Response::redirect($this->base() . '/admin/users/create?error=employee_code_taken');
        }
        if ($password === '') {
            // Mot de passe par défaut « 0000 », comme l'API et la réinitialisation : signalé comme à changer (rappel à
            // chaque connexion, pas de jeton API). Avant : nom+prénom+code employé, devinable par n'importe quel collègue
            // et jamais signalé.
            $password = \kintai\Core\Auth\PasswordPolicy::DEFAULT_PASSWORD;
        }

        // Un seul sélecteur "Rôle" (Owner + rôles par store) remplace l'ancien
        // couple "Rôle global" (is_admin) / "Rôle dans le store" (store_role_id).
        // Seul un requérant déjà Owner peut créer un compte Owner : sinon un
        // simple détenteur de employees.create pourrait s'auto-élever en
        // sélectionnant le rôle système dans ce formulaire.
        $isRequesterOwner = !empty($request->getAttribute('auth_user')['is_admin']);
        $selectedRole = $this->roleSync->findRole((int) $request->post('role_id', 0));
        $isOwner      = $isRequesterOwner && $selectedRole !== null && !empty($selectedRole['is_system']);

        $saved    = $this->users->save([
            // Vide : « Nom Prénom » (UserDisplayName).
            'display_name'       => UserDisplayName::resolve($request->post('display_name', ''), $request->post('first_name', ''), $request->post('last_name', '')),
            'first_name'         => $request->post('first_name', ''),
            'last_name'          => $request->post('last_name', ''),
            'email'              => $request->post('email', ''),
            'phone'              => $request->post('phone', '') ?: null,
            'mobile_phone'       => $request->post('mobile_phone', '') ?: null,
            'furigana_last_name'  => $request->post('furigana_last_name', '') ?: null,
            'furigana_first_name' => $request->post('furigana_first_name', '') ?: null,
            'gender'             => $request->post('gender', '') ?: null,
            'tax_classification' => $request->post('tax_classification', '') ?: null,
            'birth_date'         => $request->post('birth_date', '') ?: null,
            'education'          => $request->post('education', '') ?: null,
            'postal_code'        => $request->post('postal_code', '') ?: null,
            'address'            => $request->post('address', '') ?: null,
            'guarantor_name'     => $request->post('guarantor_name', '') ?: null,
            'guarantor_phone'    => $request->post('guarantor_phone', '') ?: null,
            'color'              => $request->post('color', '#3B82F6'),
            'employee_code'      => $empCode,
            'password_hash'      => \kintai\Core\Auth\PasswordHasher::hash($password),
            'is_active'          => 1,
        ]);
        $this->roleSync->syncOwnerRole((int) ($saved['id'] ?? 0), $isOwner);

        // Affecter au magasin si sélectionné
        $storeId = (int) $request->post('store_id', 0);
        if ($storeId > 0 && !empty($saved['id'])) {
            $this->assertStoreAccess($request, $storeId);
            // Owner : pas de rôle par store à synchroniser, l'affectation globale suffit.
            $role = $isOwner ? null : ($selectedRole ?? $this->roleSync->defaultStoreRole());
            $membership = $this->storeUsers->save([
                'store_id' => $storeId,
                'user_id'  => (int) $saved['id'],
            ]);
            if ($role !== null) {
                $this->roleSync->syncStoreRoleById((int) $saved['id'], $storeId, (int) $role['id']);
            }
        }

        if (!empty($saved['id'])) {
            $this->events()->dispatch(new EmployeeCreated($saved, $storeId > 0 ? $storeId : null, self::authUserId($request), 'form'));
        }

        $this->auditLogger->log($request, 'user.created', 'user', (int) ($saved['id'] ?? 0), [
            'email' => $request->post('email', ''),
        ]);
        return Response::redirect($this->base() . '/admin/users?success=created');
    }

    /**
     * Création rapide d'un utilisateur depuis la prévisualisation d'import Excel.
     * Retourne JSON {success, user: {id, name}} ou {success: false, error}.
     */
    public function quickCreateUser(Request $request): Response
    {
        try {
            $this->planLimits->assertCanCreateEmployee();
        } catch (PlanLimitExceededException) {
            return Response::json(['success' => false, 'error' => 'plan_limit_employees'], 403);
        }

        $displayName = trim($request->post('display_name', ''));
        $firstName   = trim($request->post('first_name', ''));
        $lastName    = trim($request->post('last_name', ''));
        $email       = trim($request->post('email', ''));
        $phone       = trim($request->post('phone', '')) ?: null;
        $empCode     = strtoupper(trim($request->post('employee_code', ''))) ?: null;
        $password    = $request->post('password', '');
        $color       = $request->post('color', '#3B82F6');
        ColorRule::assert($color);
        // Seul un requérant déjà Owner peut créer un autre compte Owner (voir
        // storeUser()/updateUser() pour la même garde).
        $isAdmin     = !empty($request->getAttribute('auth_user')['is_admin']) && $request->post('is_admin') === '1';
        $storeId     = (int) $request->post('store_id', 0);
        $storeRoleId = (int) $request->post('store_role_id', 0);

        if ($lastName === '' || $firstName === '') {
            return Response::json(['success' => false, 'error' => 'name_required'], 422);
        }

        $furiganaLastName  = trim($request->post('furigana_last_name', ''));
        $furiganaFirstName = trim($request->post('furigana_first_name', ''));
        if ($furiganaLastName === '' || $furiganaFirstName === '') {
            return Response::json(['success' => false, 'error' => 'furigana_required'], 422);
        }

        $displayName = UserDisplayName::resolve($displayName, $firstName, $lastName);

        if ($email === '') {
            // Générer un email unique @kintai.local
            $base = mb_strtolower($firstName . '.' . $lastName);
            $base = preg_replace('/[^a-z0-9.]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base));
            $base = trim($base, '.');
            if ($base === '') $base = 'user';
            $email   = $base . '@kintai.local';
            $attempt = 0;
            while ($this->users->findByEmail($email) !== null) {
                $attempt++;
                $email = $base . $attempt . '@kintai.local';
                if ($attempt > 99) break;
            }
        } elseif ($this->users->findByEmail($email) !== null) {
            // Email fourni explicitement (pas auto-généré) : évite un crash SQL
            // (contrainte unique) si l'employé existe déjà sous un autre nom Excel
            // non reconnu par le matching de l'import.
            return Response::json(['success' => false, 'error' => 'email_taken'], 409);
        }

        // Vérifier unicité du code employé si fourni
        if ($empCode !== null && !EmployeeRouteBinder::isValidCode($empCode)) {
            return Response::json(['success' => false, 'error' => 'employee_code_invalid_url'], 422);
        }
        if ($empCode !== null && $this->users->findByEmployeeCode($empCode) !== null) {
            return Response::json(['success' => false, 'error' => 'employee_code_taken'], 409);
        }

        if ($password === '') {
            // Même règle que le formulaire : « 0000 », signalé comme à changer.
            $password = \kintai\Core\Auth\PasswordPolicy::DEFAULT_PASSWORD;
        }

        $saved = $this->users->save([
            'display_name'  => $displayName,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'email'         => $email,
            'phone'         => $phone,
            'mobile_phone'  => $request->post('mobile_phone', '') ?: null,
            'furigana_last_name'  => $furiganaLastName,
            'furigana_first_name' => $furiganaFirstName,
            'gender'        => $request->post('gender', '') ?: null,
            'birth_date'    => $request->post('birth_date', '') ?: null,
            'education'     => $request->post('education', '') ?: null,
            'postal_code'   => $request->post('postal_code', '') ?: null,
            'address'       => $request->post('address', '') ?: null,
            'employee_code' => $empCode,
            'password_hash' => \kintai\Core\Auth\PasswordHasher::hash($password),
            'is_active'     => 1,
            'color'         => $color,
        ]);

        if (empty($saved['id'])) {
            return Response::json(['success' => false, 'error' => __('error_creation_failed')], 500);
        }
        $this->roleSync->syncOwnerRole((int) $saved['id'], $isAdmin);

        if ($storeId > 0) {
            $this->assertStoreAccess($request, $storeId);
            $role = $this->roleSync->findAssignableRole($storeRoleId) ?? $this->roleSync->defaultStoreRole();
            $this->storeUsers->save([
                'store_id' => $storeId,
                'user_id'  => (int) $saved['id'],
            ]);
            if ($role !== null) {
                $this->roleSync->syncStoreRoleById((int) $saved['id'], $storeId, (int) $role['id']);
            }
        }

        $this->events()->dispatch(new EmployeeCreated($saved, $storeId > 0 ? $storeId : null, self::authUserId($request), 'excel_import'));

        $this->auditLogger->log($request, 'user.created', 'user', (int) $saved['id'], [
            'email'  => $email,
            'source' => 'excel_import',
        ]);

        return Response::json([
            'success' => true,
            'user'    => ['id' => (int) $saved['id'], 'name' => $displayName],
        ]);
    }

    /**
     * Vérifie en temps réel si un code employé est disponible. Retourne JSON {available: bool}.
     * `exclude_id` (édition) : ignore la correspondance si c'est le propre code de l'utilisateur en cours d'édition.
     */
    public function checkEmployeeCode(Request $request): Response
    {
        $code = strtoupper(trim($request->query('code', '')));
        if ($code === '') {
            return Response::json(['available' => true]);
        }
        $existing  = $this->users->findByEmployeeCode($code);
        $excludeId = (int) $request->query('exclude_id', 0);
        $taken     = $existing !== null && (int) $existing['id'] !== $excludeId;
        return Response::json(['available' => !$taken]);
    }

    /**
     * Vérifie en temps réel si une adresse email est disponible. Retourne JSON {available: bool}.
     * `exclude_id` (édition) : ignore la correspondance si c'est le propre email de l'utilisateur en cours d'édition.
     */
    public function checkEmail(Request $request): Response
    {
        $email = trim($request->query('email', ''));
        if ($email === '') {
            return Response::json(['available' => true]);
        }
        $existing  = $this->users->findByEmail($email);
        $excludeId = (int) $request->query('exclude_id', 0);
        $taken     = $existing !== null && (int) $existing['id'] !== $excludeId;
        return Response::json(['available' => !$taken]);
    }

    public function editUser(Request $request): Response
    {
        $user = $this->users->findById((int) $request->param('id'));
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }
        $this->assertCanActOnUser($request, (int) $user['id'], 'employees.view');

        $userId = (int) $user['id'];
        $user['is_admin'] = in_array($userId, $this->permissions->ownerUserIds(), true) ? 1 : 0;

        // Shift types des stores où l'utilisateur est membre
        $memberships   = $this->storeUsers->findByUser($userId);
        $userStoreIds  = array_map(fn($m) => (int) $m['store_id'], $memberships);
        $userStoreIds  = array_unique($userStoreIds);

        $userShiftTypes = empty($userStoreIds)
            ? []
            : $this->shiftTypes->findByStores($userStoreIds);

        // Taux actuels indexés par shift_type_id
        $userRates = [];
        foreach ($this->userRates->findByUser($userId) as $r) {
            $userRates[(int) $r['shift_type_id']] = $r;
        }

        // Map store_id → store name pour l'affichage
        $storesMap = [];
        foreach ($this->stores->findAllWithDeleted() as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? '#' . $s['id'];
        }

        // Un type peut désormais couvrir plusieurs stores : n'afficher, pour
        // chaque type, que ses stores en commun avec ceux de cet employé
        // (les autres stores éventuels du type n'ont pas de sens dans ce tableau).
        $typeStoreNames = [];
        foreach ($userShiftTypes as $t) {
            $tid = (int) $t['id'];
            $names = array_values(array_filter(array_map(
                fn ($sid) => $storesMap[$sid] ?? null,
                array_intersect($this->shiftTypes->getStoreIds($tid), $userStoreIds),
            )));
            sort($names);
            $typeStoreNames[$tid] = $names;
        }

        // Types de shift groupés par store (tableau Stores fusionné : une ligne par
        // couple store/type, Store/Rôle/Cotisations en rowspan sur ce groupe).
        $shiftTypesByStore = [];
        foreach ($userShiftTypes as $t) {
            $tid = (int) $t['id'];
            foreach (array_intersect($this->shiftTypes->getStoreIds($tid), $userStoreIds) as $sid) {
                $shiftTypesByStore[$sid][] = $t;
            }
        }

        // Appartenance aux stores (enrichie avec nom du store, rôle, cotisations)
        $roleMap = $this->roleSync->storeRoleMapForUser($userId);
        $userMemberships = [];
        foreach ($memberships as $m) {
            $sid = (int) $m['store_id'];
            $mid = (int) ($m['id'] ?? 0);
            $userMemberships[] = array_merge($m, [
                'store_name'         => $storesMap[$sid] ?? '#' . $sid,
                'role_name'          => $roleMap[$sid]['name'] ?? '—',
                'role_id'            => $roleMap[$sid]['role_id'] ?? null,
                'role_is_managing'   => !empty($roleMap[$sid]['is_managing']),
                'store_ded_settings' => $this->stores->getDeductionSettings($sid),
                'ded_overrides'      => ['subject_to_deductions' => $mid > 0 ? $this->storeUsers->getSubjectToDeductions($mid) : false],
            ]);
        }

        // Stores disponibles pour ajouter l'utilisateur (ceux où il n'est pas encore)
        $availableStores = array_values(array_filter(
            $this->availableStores($this->managedIds($request)),
            fn($s) => !in_array((int) $s['id'], $userStoreIds, true)
        ));

        $this->auditLogger->log($request, 'user.viewed', 'user', $userId, [], $userStoreIds[0] ?? null);

        return Response::html($this->view->render('staff.users-form', [
            'title'            => 'Modifier ' . htmlspecialchars($user['display_name'] ?? ''),
            'mode'             => 'edit',
            'user'             => $user,
            'user_shift_types' => $userShiftTypes,
            'shift_types_by_store' => $shiftTypesByStore,
            'user_rates'       => $userRates,
            'stores_map'       => $storesMap,
            'type_store_names' => $typeStoreNames,
            'user_memberships' => $userMemberships,
            'available_stores' => $availableStores,
            'all_stores'       => $this->availableStores($this->managedIds($request)),
            'assignable_roles'      => $this->roleSync->assignableStoreRoles(),
            'default_store_role_id' => (int) ($this->roleSync->defaultStoreRole()['id'] ?? 0),
            'owner_role_name'       => (string) ($this->roleSync->ownerRole()['name'] ?? __('admin')),
        ], 'layout.app'));
    }

    public function updateUser(Request $request): Response
    {
        $user = $this->users->findById((int) $request->param('id'));
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }
        $this->assertCanActOnUser($request, (int) $user['id'], 'employees.update');
        ColorRule::assert($request->post('color', $user['color'] ?? '#3B82F6'));

        // Un Owner ne se désactive pas lui-même, et le dernier Owner actif ne peut être ni désactivé ni dégradé.
        // Champ absent de la requête : état actuel conservé (audit du 13/10/2026, M12). Avant, une requête sans
        // is_active désactivait le compte, et sans is_admin (envoyée par un Owner) lui retirait le statut Owner.
        $newIsActive  = $request->postFlag('is_active', $user['is_active'] ?? 1);
        $newIsOwner   = $request->postFlag('is_admin', $this->ownerGuard->isOwner((int) $user['id'])) === 1;
        $deactivating = !empty($user['is_active']) && $newIsActive === 0;
        $demoting     = !empty($request->getAttribute('auth_user')['is_admin']) && !$newIsOwner;
        if ($deactivating) {
            $this->ownerGuard->assertNotSelfUnlessOwner((int) ($request->getAttribute('auth_user')['id'] ?? 0), (int) $user['id']);
        }
        if ($deactivating || $demoting) {
            $this->ownerGuard->assertCanRemoveOwner((int) ($request->getAttribute('auth_user')['id'] ?? 0), (int) $user['id']);
        }

        $email = trim($request->post('email', $user['email'] ?? ''));
        if ($email !== ($user['email'] ?? '')) {
            $existing = $this->users->findByEmail($email);
            if ($existing !== null && (int) $existing['id'] !== (int) $user['id']) {
                return $this->updateUserError($request, $user, 'email_taken');
            }
        }

        $lastName  = trim($request->post('last_name', $user['last_name'] ?? ''));
        $firstName = trim($request->post('first_name', $user['first_name'] ?? ''));
        if ($lastName === '' || $firstName === '') {
            return $this->updateUserError($request, $user, 'name_required');
        }

        $furiganaLastName  = trim($request->post('furigana_last_name', $user['furigana_last_name'] ?? ''));
        $furiganaFirstName = trim($request->post('furigana_first_name', $user['furigana_first_name'] ?? ''));
        if ($furiganaLastName === '' || $furiganaFirstName === '') {
            return $this->updateUserError($request, $user, 'furigana_required');
        }

        $empCode = strtoupper(trim((string) $request->post('employee_code', $user['employee_code'] ?? ''))) ?: null;
        if ($empCode !== null && !EmployeeRouteBinder::isValidCode($empCode)) {
            return $this->updateUserError($request, $user, 'employee_code_invalid_url');
        }
        $holder = $empCode !== null ? $this->users->findByEmployeeCode($empCode) : null;
        if ($holder !== null && (int) $holder['id'] !== (int) $user['id']) {
            return $this->updateUserError($request, $user, 'employee_code_taken');
        }
        // Champ facultatif : vide = null, absent = valeur actuelle.
        $optional = fn(string $field): mixed => $request->hasPost($field) ? ($request->post($field, '') ?: null) : ($user[$field] ?? null);
        $data = array_merge($user, [
            // Absent : valeur actuelle ; vide : « Nom Prénom » (UserDisplayName).
            'display_name'       => UserDisplayName::resolve($request->post('display_name', $user['display_name'] ?? ''), $firstName, $lastName),
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'email'              => $email,
            'phone'              => $optional('phone'),
            'mobile_phone'       => $optional('mobile_phone'),
            'furigana_last_name'  => $furiganaLastName,
            'furigana_first_name' => $furiganaFirstName,
            'gender'             => $optional('gender'),
            'tax_classification' => $optional('tax_classification'),
            'birth_date'         => $optional('birth_date'),
            'education'          => $optional('education'),
            'postal_code'        => $optional('postal_code'),
            'address'            => $optional('address'),
            'guarantor_name'     => $optional('guarantor_name'),
            'guarantor_phone'    => $optional('guarantor_phone'),
            'color'              => $request->post('color', $user['color'] ?? '#3B82F6'),
            'employee_code'      => $empCode,
            'is_active'          => $newIsActive,
        ]);

        // Mettre à jour le mot de passe uniquement si fourni
        $newPassword = $request->post('password', '');
        if ($newPassword !== '') {
            $data['password_hash'] = \kintai\Core\Auth\PasswordHasher::hash($newPassword);
        }

        $this->users->save($data);
        // Nouveau mot de passe ou compte désactivé : on révoque cookies « rester connecté » et jetons d'API
        // (les sessions déjà ouvertes tombent seules : empreinte du mot de passe / compte inactif).
        if ($newPassword !== '' || (!empty($user['is_active']) && empty($data['is_active']))) {
            $this->revoker?->revokeAllFor((int) $user['id']);
        }
        // Seul un requérant déjà Owner peut accorder ou retirer le statut Owner
        // d'un compte (y compris le sien) — sinon n'importe quel détenteur de
        // employees.update pourrait s'auto-promouvoir en postant is_admin=1.
        // Un non-Owner ne touche donc jamais au statut Owner de la cible, ni
        // dans un sens ni dans l'autre.
        if (!empty($request->getAttribute('auth_user')['is_admin'])) {
            $this->roleSync->syncOwnerRole((int) $user['id'], $newIsOwner);
        }
        $this->auditLogger->logUpdate($request, 'user.updated', 'user', (int) $user['id'], $user, $data, [], null, null);

        // L'auto-save de la page d'édition (voir user-form-autosave.js) soumet ce même
        // endpoint en AJAX à chaque changement de champ : dans ce cas on répond en JSON
        // sans rediriger, pour rester sur la page. La soumission classique (bouton
        // "Enregistrer") continue de rediriger vers la liste comme avant.
        if ($request->wantsJson()) {
            return Response::json(['success' => true]);
        }
        return Response::redirect($this->base() . '/admin/users?success=updated');
    }

    private function updateUserError(Request $request, array $user, string $error): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['success' => false, 'error' => $error], 422);
        }
        return Response::redirect($this->base() . '/admin/users/' . employee_segment($user['id']) . '/edit?error=' . $error);
    }

    public function deleteUser(Request $request): Response
    {
        $id = (int) $request->param('id');
        $this->assertCanActOnUser($request, $id, 'employees.delete');
        $this->ownerGuard->assertNotSelfUnlessOwner((int) ($request->getAttribute('auth_user')['id'] ?? 0), $id);
        $this->ownerGuard->assertCanRemoveOwner((int) ($request->getAttribute('auth_user')['id'] ?? 0), $id);
        // Anonymisation plutôt qu'effacement : l'historique (shifts, pointages, paie) reste intact, quel que
        // soit le pilote de base de données (voir UserAnonymizer).
        $this->anonymizer()->anonymize($id);
        $this->auditLogger->log($request, 'user.deleted', 'user', $id, []);
        return Response::redirect($this->base() . '/admin/users?success=deleted');
    }

    public function resetPassword(Request $request): Response
    {
        $user   = $this->users->findById((int) $request->param('id'));
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }
        $this->assertCanActOnUser($request, (int) $user['id'], 'employees.update');

        $oldUser = $user;
        $user['password_hash'] = \kintai\Core\Auth\PasswordHasher::hash(\kintai\Core\Auth\PasswordPolicy::DEFAULT_PASSWORD);
        $this->users->save($user);
        $this->revoker?->revokeAllFor((int) $user['id']);
        $this->auditLogger->logUpdate($request, 'user.password_reset', 'user', (int) $user['id'], $oldUser, $user, [
            'email' => $user['email'] ?? null,
        ], null, null);

        return Response::redirect($this->base() . '/admin/users/' . employee_segment($user['id']) . '/edit?success=password_reset');
    }

    // -------------------------------------------------------------------------
    // Users — taux horaires par type de shift
    // -------------------------------------------------------------------------

    public function setUserRate(Request $request): Response
    {
        $userId = (int) $request->param('id');
        $user   = $this->users->findById($userId);
        if ($user === null) {
            throw new NotFoundException(__('error_user_not_found'));
        }
        $this->assertCanActOnUser($request, $userId, 'employees.update');

        $shiftTypeId = (int) $request->post('shift_type_id', 0);
        $rateRaw     = $request->post('hourly_rate', '');

        // Vérifier que le type de shift est activé sur au moins un store accessible
        $type = $this->shiftTypes->findById($shiftTypeId);
        if ($type !== null) {
            $this->assertAnyStoreAccess($request, $this->shiftTypes->getStoreIds($shiftTypeId));
        }

        $existing = $this->userRates->findRate($userId, $shiftTypeId);

        if ($rateRaw === '') {
            if ($existing !== null) {
                $this->userRates->delete((int) $existing['id']);
                $this->auditLogger->log($request, 'user_rate.deleted', 'user_rate', (int) $existing['id'], [
                    'user_id'       => $userId,
                    'shift_type_id' => $shiftTypeId,
                ]);
            }
        } else {
            $data = [
                'user_id'       => $userId,
                'shift_type_id' => $shiftTypeId,
                'hourly_rate'   => (float) $rateRaw,
            ];
            if ($existing !== null) {
                $data = array_merge($existing, $data);
            }
            $saved = $this->userRates->save($data);
            if ($existing !== null) {
                $this->auditLogger->logUpdate($request, 'user_rate.saved', 'user_rate', (int) ($saved['id'] ?? 0), $existing, $saved, [
                    'user_id'       => $userId,
                    'shift_type_id' => $shiftTypeId,
                ], null, null);
            } else {
                $this->auditLogger->log($request, 'user_rate.saved', 'user_rate', (int) ($saved['id'] ?? 0), [
                    'user_id'       => $userId,
                    'shift_type_id' => $shiftTypeId,
                    'hourly_rate'   => (float) $rateRaw,
                ]);
            }
        }

        return Response::redirect($this->base() . '/admin/users/' . employee_segment($userId) . '/edit?success=rate_updated');
    }

    public function deleteUserRate(Request $request): Response
    {
        $userId = (int) $request->param('id');
        $rid    = (int) $request->param('rid');
        $this->assertCanActOnUser($request, $userId, 'employees.update');

        $rate = $this->userRates->findById($rid);
        if ($rate !== null && (int) $rate['user_id'] === $userId) {
            $type = $this->shiftTypes->findById((int) $rate['shift_type_id']);
            if ($type !== null) {
                $this->assertAnyStoreAccess($request, $this->shiftTypes->getStoreIds((int) $type['id']));
            }
            $this->userRates->delete($rid);
            $this->auditLogger->log($request, 'user_rate.deleted', 'user_rate', $rid, [
                'user_id'       => $userId,
                'shift_type_id' => $rate['shift_type_id'] ?? null,
            ]);
        }

        return Response::redirect($this->base() . '/admin/users/' . employee_segment($userId) . '/edit?success=rate_deleted');
    }

    /** Identifiant de l'utilisateur connecté, null si inconnu. */
    private static function authUserId(Request $request): ?int
    {
        $id = (int) ($request->getAttribute('auth_user')['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
