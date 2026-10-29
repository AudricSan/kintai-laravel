<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use kintai\Contract\Extension\Action;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use Illuminate\Container\Container;
use kintai\Tests\Support\FakeBundleManagerFactory;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie que staff/users.php, staff/stores.php, staff/stores-form.php et
 * staff/users-form.php gardent bien leurs liens/formulaires vers des actions
 * protégées derrière le helper $can()/$user_can, plutôt que de les afficher
 * inconditionnellement à quiconque atteint la page conteneur (protégée par
 * une permission plus large que celle des actions qu'elle donne accès à).
 */
final class StaffViewPermissionGatesTest extends TestCase
{
    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->view = new ViewRenderer(dirname(__DIR__, 2) . '/app/UI/View');

        $router = kintai_test_registrar();
        $router->get('/admin/users', [\stdClass::class, 'x'], name: 'admin.users');
        $router->get('/admin/users/create', [\stdClass::class, 'x'], name: 'admin.users.create');
        $router->get('/admin/users/export/pdf', [\stdClass::class, 'x'], name: 'admin.users.export_pdf');
        $router->get('/admin/users/export/json', [\stdClass::class, 'x'], name: 'admin.users.export_json');
        $router->get('/admin/stores', [\stdClass::class, 'x'], name: 'admin.stores');
        $router->get('/admin/stores/create', [\stdClass::class, 'x'], name: 'admin.stores.create');
    }

    private function canFn(array $granted): \Closure
    {
        return fn(string $key): bool => in_array($key, $granted, true);
    }

    // -------------------------------------------------------------------------
    // staff/users.php
    // -------------------------------------------------------------------------

    public function testUsersListHidesCreateButtonAndRowActionsWithoutPermissions(): void
    {
        $html = $this->view->renderPartial('staff.users', [
            'BASE_URL'         => '',
            'users'            => [['id' => 5, 'display_name' => 'Jean Dupont', 'email' => 'j@d.com', 'is_admin' => 0, 'is_active' => 1]],
            'user_stats'       => [],
            'available_stores' => [],
            'store_names'      => [],
            'user_store_ids'   => [],
            'user_store_map'   => [5 => 1],
            'user_can'         => $this->canFn([]),
        ]);

        $this->assertStringNotContainsString('/admin/users/create', $html);
        $this->assertStringNotContainsString('employee-report/5/stats', $html);
        $this->assertStringNotContainsString('reports/salary/create?user_id=5', $html);
        $this->assertStringNotContainsString('reports/resignation/create?user_id=5', $html);
    }

    public function testUsersListShowsRowActionsWhenPermissionsGranted(): void
    {
        // Liens fournis par les bundles salary-report et resignation-report (Bundle::actions()) :
        // seulement quand ils sont actifs.
        $container = Container::getInstance();
        $manager = FakeBundleManagerFactory::withActiveSlugs(['salary-report', 'resignation-report'], ['salary-report' => [
            new Action('salary_report', Action::EMPLOYEE_ROW, 'salary_report', static fn(array $c): string => '/admin/stores/' . (int) $c['store_id'] . '/reports/salary/create?user_id=' . (int) $c['user_id'], '💰', permission: 'payroll.generate', order: 10),
        ], 'resignation-report' => [
            new Action('resign', Action::EMPLOYEE_ROW, 'resign', static fn(array $c): string => '/admin/stores/' . (int) $c['store_id'] . '/reports/resignation/create?user_id=' . (int) $c['user_id'], '✕', Action::DANGER, 'resignation_reports.create', order: 20),
        ]]);
        $container->instance(BundleManager::class, $manager);
        $container->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
        try {
            $html = $this->renderUsersWithAllPermissions();
        } finally {
            $container->offsetUnset(BundleManager::class);
            $container->offsetUnset(ExtensionRegistry::class);
        }

        $this->assertStringContainsString('/admin/users/create', $html);
        $this->assertStringContainsString('employee-report/5/stats', $html);
        $this->assertStringContainsString('reports/salary/create?user_id=5', $html);
        $this->assertStringContainsString('reports/resignation/create?user_id=5', $html);
    }

    public function testUsersListHidesBundleActionsWhenTheirBundlesAreInactive(): void
    {
        $html = $this->renderUsersWithAllPermissions();

        $this->assertStringContainsString('employee-report/5/stats', $html);
        $this->assertStringNotContainsString('reports/salary/create', $html);
        $this->assertStringNotContainsString('reports/resignation/create', $html);
    }

    private function renderUsersWithAllPermissions(): string
    {
        return $this->view->renderPartial('staff.users', [
            'BASE_URL'         => '',
            'users'            => [['id' => 5, 'display_name' => 'Jean Dupont', 'email' => 'j@d.com', 'is_admin' => 0, 'is_active' => 1]],
            'user_stats'       => [],
            'available_stores' => [],
            'store_names'      => [],
            'user_store_ids'   => [],
            'user_store_map'   => [5 => 1],
            'user_can'         => $this->canFn(['employees.create', 'payroll.view', 'payroll.generate', 'resignation_reports.create']),
        ]);
    }

    // -------------------------------------------------------------------------
    // staff/stores.php
    // -------------------------------------------------------------------------

    public function testStoresListHidesCreateButtonAndActionsWithoutPermissions(): void
    {
        $html = $this->view->renderPartial('staff.stores', [
            'BASE_URL' => '',
            'stores'   => [['id' => 1, 'name' => 'Store A', 'code' => 'A', 'is_active' => 1]],
            'user_can' => $this->canFn([]),
        ]);

        $this->assertStringNotContainsString('/admin/stores/create', $html);
        $this->assertStringNotContainsString('/admin/stores/1/stats', $html);
        $this->assertStringNotContainsString('/admin/stores/1/employee-report', $html);
        $this->assertStringNotContainsString('/admin/stores/1/delete', $html);
    }

    public function testStoresListShowsCreateAndDeleteWhenGranted(): void
    {
        $html = $this->view->renderPartial('staff.stores', [
            'BASE_URL' => '',
            'stores'   => [['id' => 1, 'name' => 'Store A', 'code' => 'A', 'is_active' => 1]],
            'user_can' => $this->canFn(['stores.create', 'stores.delete', 'payroll.view']),
        ]);

        $this->assertStringContainsString('/admin/stores/create', $html);
        $this->assertStringContainsString('/admin/stores/1/stats', $html);
        $this->assertStringContainsString('/admin/stores/1/delete', $html);
    }

    // -------------------------------------------------------------------------
    // staff/stores-form.php
    // -------------------------------------------------------------------------

    public function testStoresFormHidesMemberManagementFormsWithoutPermissions(): void
    {
        $html = $this->view->renderPartial('staff.stores-form', [
            'BASE_URL' => '',
            'mode'     => 'edit',
            'store'    => ['id' => 1, 'name' => 'Store A'],
            'members'  => [['id' => 10, 'user_name' => 'Jean', 'user_email' => 'j@d.com', 'is_active' => 1, 'role_id' => 2]],
            'available' => [['id' => 20, 'first_name' => 'Paul', 'last_name' => 'Martin', 'email' => 'p@m.com']],
            'assignable_roles' => [['id' => 2, 'name' => 'Manager']],
            'user_can' => $this->canFn([]),
        ]);

        $this->assertStringNotContainsString('/members/10/role', $html);
        $this->assertStringNotContainsString('/members/10/deductions', $html);
        $this->assertStringNotContainsString('/members/10/delete', $html);
        $this->assertStringNotContainsString('/stores/1/members"', $html);
    }

    public function testStoresFormShowsMemberManagementFormsWhenGranted(): void
    {
        $html = $this->view->renderPartial('staff.stores-form', [
            'BASE_URL' => '',
            'mode'     => 'edit',
            'store'    => ['id' => 1, 'name' => 'Store A'],
            'members'  => [['id' => 10, 'user_name' => 'Jean', 'user_email' => 'j@d.com', 'is_active' => 1, 'role_id' => 2]],
            'available' => [['id' => 20, 'first_name' => 'Paul', 'last_name' => 'Martin', 'email' => 'p@m.com']],
            'assignable_roles' => [['id' => 2, 'name' => 'Manager']],
            'user_can' => $this->canFn(['employees.update', 'payroll.view']),
        ]);

        $this->assertStringContainsString('/members/10/role', $html);
        $this->assertStringContainsString('/members/10/deductions', $html);
        $this->assertStringContainsString('/members/10/delete', $html);
    }

    // -------------------------------------------------------------------------
    // staff/users-form.php
    // -------------------------------------------------------------------------

    public function testUsersFormIsReadOnlyWithoutUpdatePermission(): void
    {
        $html = $this->view->renderPartial('staff.users-form', [
            'BASE_URL' => '',
            'mode'     => 'edit',
            'user'     => ['id' => 5, 'display_name' => 'Jean Dupont'],
            'user_memberships' => [],
            'user_can' => $this->canFn(['employees.view']),
        ]);

        $this->assertStringContainsString('readonly_no_permission_notice', $html);
        $this->assertStringContainsString('<fieldset disabled', $html);
    }

    public function testUsersFormIsEditableWithUpdatePermission(): void
    {
        $html = $this->view->renderPartial('staff.users-form', [
            'BASE_URL' => '',
            'mode'     => 'edit',
            'user'     => ['id' => 5, 'display_name' => 'Jean Dupont'],
            'user_memberships' => [],
            'user_can' => $this->canFn(['employees.view', 'employees.update']),
        ]);

        $this->assertStringNotContainsString('readonly_no_permission_notice', $html);
        $this->assertStringNotContainsString('<fieldset disabled', $html);
    }

    public function testUsersFormAlwaysEditableInCreateMode(): void
    {
        // En création, aucune permission d'update préexistante à vérifier :
        // employees.create a déjà été validé par PermissionMiddleware pour atteindre la page.
        $html = $this->view->renderPartial('staff.users-form', [
            'BASE_URL' => '',
            'mode'     => 'create',
            'user'     => [],
            'user_memberships' => [],
            'user_can' => $this->canFn([]),
        ]);

        $this->assertStringNotContainsString('readonly_no_permission_notice', $html);
        $this->assertStringNotContainsString('<fieldset disabled', $html);
    }

    // -------------------------------------------------------------------------
    // staff/employee-stats.php
    // -------------------------------------------------------------------------

    /**
     * Le bouton « Rapport de salaire » des statistiques d'un employé vient des liens déclarés par les bundles pour une
     * ligne du rapport employés (Action::STORE_REPORT_ROW) : absent sans salary-report actif (il était codé en dur et
     * menait à une page inexistante quand le bundle était désactivé)…
     *
     * Processus séparé : la vue déclare des fonctions, elle ne peut être rendue qu'une fois par processus.
     */
    #[RunInSeparateProcess]
    public function testEmployeeStatsHasNoBundleLinkWithoutActiveBundle(): void
    {
        $this->assertStringNotContainsString('reports/salary/create', $this->renderEmployeeStats());
    }

    /** … et présent, en bouton principal de l'en-tête, avec salary-report actif. */
    #[RunInSeparateProcess]
    public function testEmployeeStatsShowsTheLinksOfActiveBundles(): void
    {
        $container = Container::getInstance();
        $manager = FakeBundleManagerFactory::withActiveSlugs(['salary-report'], ['salary-report' => [
            new Action('salary_report', Action::STORE_REPORT_ROW, 'salary_report', static fn(array $c): string => '/admin/stores/' . (int) $c['store_id'] . '/reports/salary/create?user_id=' . (int) $c['user_id'], '💰', order: 10),
        ]]);
        $container->instance(BundleManager::class, $manager);
        $container->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
        try {
            $html = $this->renderEmployeeStats();
        } finally {
            $container->offsetUnset(BundleManager::class);
            $container->offsetUnset(ExtensionRegistry::class);
        }

        $this->assertMatchesRegularExpression('#class="btn btn--primary btn--sm" href="/admin/stores/4/reports/salary/create\?user_id=9">💰 #', $html);
    }

    private function renderEmployeeStats(): string
    {
        return $this->view->renderPartial('staff.employee-stats', [
            'BASE_URL' => '', 'store' => ['id' => 4, 'name' => 'Shibuya'], 'user' => ['id' => 9, 'last_name' => 'Sato', 'first_name' => 'Hanako'],
            'membership' => ['role_name' => 'Staff', 'role_is_managing' => false], 'period' => 30, 'since' => '2026-09-14',
            'today' => '2026-10-14', 'currency' => 'JPY', 'totalShifts' => 0, 'grossHours' => 0.0, 'netHours' => 0.0, 'cost' => 0.0,
            'anyRate' => false, 'workDays' => 0, 'absDays' => 0, 'maxConsec' => 0, 'swapCount' => 0, 'monthlyHours' => [],
            'typeHours' => [], 'dowChart' => [], 'recentShifts' => [], 'storeTypesMap' => [], 'user_can' => $this->canFn([]),
        ]);
    }
}
