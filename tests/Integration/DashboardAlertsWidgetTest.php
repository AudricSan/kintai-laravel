<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use kintai\Core\Container;
use kintai\UI\Controller\Web\HomeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Régression : le widget « Alertes » (dashboard_alerts) était proposé dans « Personnaliser » et calculé par
 * DashboardAlertService à chaque chargement, mais la vue ne l'affichait nulle part.
 */
final class DashboardAlertsWidgetTest extends TestCase
{
    private ViewRenderer $view;

    protected function setUp(): void
    {
        $this->view = new ViewRenderer(dirname(__DIR__, 2) . '/app/UI/View');

        $router = kintai_test_registrar();
        $router->get('/admin/dashboard/widgets', [\stdClass::class, 'x'], name: 'admin.dashboard.widgets');
        $router->post('/admin/dashboard/layout', [\stdClass::class, 'x'], name: 'admin.dashboard.layout');
        $router->get('/admin/shifts/{id}/edit', [\stdClass::class, 'x'], name: 'admin.shifts.edit');
        $router->get('/admin/users/{id}/edit', [\stdClass::class, 'x'], name: 'admin.users.edit');
        $router->get('/admin/requests', [\stdClass::class, 'x'], name: 'admin.requests');
    }

    private function render(?array $alerts, array $widgets = ['dashboard_alerts']): string
    {
        return $this->view->renderPartial('dashboard.index', [
            'BASE_URL'         => '',
            'stats'            => [],
            'dashboard_alerts' => $alerts,
            'enabled_widgets'  => array_flip($widgets),
            'all_widgets'      => HomeController::ADMIN_WIDGETS,
            'user_can'         => fn(string $p): bool => $p !== 'employees.view',
        ]);
    }

    private static function alerts(): array
    {
        $users = [];
        for ($i = 1; $i <= 7; $i++) {
            $users[] = ['id' => $i, 'name' => 'Employé ' . $i, 'store_id' => 1, 'store_name' => 'Store 1'];
        }
        return [
            'unfilled_shifts'     => [['id' => 42, 'store_id' => 1, 'store_name' => 'Store 1', 'shift_date' => '2026-10-15', 'start_time' => '09:00:00', 'end_time' => '17:00:00']],
            'users_without_shift' => $users,
            'stale_requests'      => [['type' => 'timeoff', 'id' => 3, 'store_id' => 1, 'store_name' => 'Store 1', 'created_at' => '2026-10-01 09:00:00']],
            'stale_timeclocks'    => [],
        ];
    }

    public function testAlertsAreDisplayed(): void
    {
        $html = $this->render(self::alerts());

        $this->assertStringContainsString('<!-- Alertes -->', $html);
        $this->assertStringContainsString('<span class="page-count">(9)</span>', $html);
        $this->assertStringContainsString('/admin/shifts/42/edit">2026-10-15 09:00–17:00 · Store 1</a>', $html);
        $this->assertStringContainsString('Employé 5 · Store 1', $html);
        $this->assertStringNotContainsString('Employé 6', $html, 'au plus 5 éléments par groupe');
        $this->assertStringNotContainsString('/admin/users/1/edit', $html, 'pas de lien sans employees.view');
        $this->assertStringContainsString('dashboard_alerts_more', $html);
        $this->assertStringContainsString('/admin/requests">timeoff #3 · Store 1 · 2026-10-01</a>', $html);
        $this->assertStringNotContainsString('dashboard_alerts_stale_timeclocks', $html, 'groupe vide masqué');
    }

    public function testEmptyStateAndDisabledWidget(): void
    {
        $empty = ['unfilled_shifts' => [], 'users_without_shift' => [], 'stale_requests' => [], 'stale_timeclocks' => []];

        $this->assertStringContainsString('dashboard_alerts_none', $this->render($empty));
        $this->assertStringNotContainsString('<!-- Alertes -->', $this->render(self::alerts(), []));
    }
}
