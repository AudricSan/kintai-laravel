<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Container\Container;
use App\Http\Controllers\HomeController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/** Tableaux de bord : widgets rendus dans l'ordre et la largeur de la disposition de l'utilisateur. */
final class DashboardGridRenderTest extends TestCase
{
    private ViewFactory $view;

    protected function setUp(): void
    {
        $this->view = kintai_test_views(dirname(__DIR__, 2) . '/resources/views');

        $router = kintai_test_registrar();
        foreach (['admin.dashboard.widgets', 'admin.dashboard.layout', 'admin.requests', 'admin.users', 'admin.stores', 'admin.shifts', 'admin.shift_types',
                  'employee.dashboard.widgets', 'employee.dashboard.layout', 'employee.shifts.day'] as $i => $name) {
            $router->post('/r' . $i, [\stdClass::class, 'x'], name: $name);
        }
    }

    /** @return list<array{string, string}> [clé, largeur] des widgets, dans l'ordre du document (6 colonnes = « half », 12 = « full ») */
    private static function items(string $html): array
    {
        preg_match_all('/data-widget-key="([a-z_]+)"\s+data-widget-width="(\d+)"/', $html, $m, PREG_SET_ORDER);
        return array_map(static fn(array $x): array => [$x[1], match ($x[2]) { '6' => 'half', '12' => 'full', default => $x[2] }], $m);
    }

    public function testManagerWidgetsFollowTheLayout(): void
    {
        $html = $this->view->make('dashboard.index', [
            'BASE_URL'        => '',
            'stats'           => ['users' => 1, 'stores' => 1, 'shifts_today' => 0],
            'shifts_today'    => [],
            'enabled_widgets' => array_flip(['kpi_counters', 'shifts_today']),
            'all_widgets'     => HomeController::ADMIN_WIDGETS,
            'bundle_widgets'  => [['key' => 'pending_timeoff', 'html' => '<div class="card">bundle</div>']],
            'widget_layout'   => [
                'order'  => ['pending_timeoff', 'shifts_today', 'kpi_counters'],
                'widths' => ['pending_timeoff' => 'half', 'shifts_today' => 'half', 'kpi_counters' => 'full'],
            ],
        ])->render();

        $this->assertSame([['pending_timeoff', 'half'], ['shifts_today', 'half'], ['kpi_counters', 'full']], self::items($html));
        $this->assertStringContainsString('data-dash-reset', $html);
    }

    public function testManagerDashboardOffersAndRendersHisEmployeeWidgets(): void
    {
        $html = $this->view->make('dashboard.index', [
            'BASE_URL'            => '',
            'stats'               => ['users' => 1, 'stores' => 1, 'shifts_today' => 0],
            'shifts_today'        => [],
            'enabled_widgets'     => array_flip(['kpi_counters', 'emp_upcoming']),
            'all_widgets'         => [...HomeController::ADMIN_WIDGETS, 'emp_shifts_today', 'emp_upcoming', 'emp_monthly_stats'],
            'employee_widgets'    => [
                'enabled'      => ['upcoming' => 1],
                'shifts_today' => [],
                'upcoming'     => [['shift_date' => '2026-10-20', 'store_id' => 3, 'start_time' => '09:00', 'end_time' => '17:00']],
                'stores_map'   => [3 => 'Shibuya'],
                'bundle'       => [],
            ],
            'widget_layout'       => ['order' => ['kpi_counters', 'emp_upcoming'], 'widths' => []],
        ])->render();

        $this->assertSame([['kpi_counters', 'full'], ['emp_upcoming', 'full']], self::items($html));
        $this->assertStringContainsString('Shibuya', $html);
        preg_match_all('/data-dash-add="([a-z_]+)"/', $html, $m);
        $this->assertContains('emp_shifts_today', $m[1]);
        $this->assertNotContains('emp_upcoming', $m[1]);
    }

    public function testHiddenWidgetsAreOfferedInTheAddMenuAndShownOnesCanBeRemoved(): void
    {
        $html = $this->view->make('employee.dashboard', [
            'BASE_URL'            => '',
            'shifts_today'        => [],
            'upcoming'            => [],
            'enabled_widgets'     => array_flip(['shifts_today']),
            'all_widgets'         => ['shifts_today', 'upcoming', 'monthly_stats'],
            'widget_layout'       => ['order' => ['shifts_today', 'upcoming', 'monthly_stats'], 'widths' => []],
        ])->render();

        preg_match_all('/data-dash-add="([a-z_]+)"/', $html, $m);
        $this->assertSame(['upcoming', 'monthly_stats'], $m[1]);
        $this->assertSame(1, substr_count($html, 'data-dash-remove'));
        $this->assertStringContainsString('data-widgets-url=', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function testFinancialAndHrWidgetsAreIndependentHalfWidthWidgetsByDefault(): void
    {
        $html = $this->view->make('dashboard.index', [
            'BASE_URL'           => '',
            'stats'              => [],
            'enabled_widgets'    => array_flip(['financial_overview', 'hr_absenteeism']),
            'all_widgets'        => HomeController::ADMIN_WIDGETS,
            'financial_overview' => ['current_month' => 1.0, 'previous_month' => 1.0, 'delta_pct' => null, 'currency' => 'EUR', 'currency_style' => 'symbol', 'trend_by_month' => []],
            'hr_stats'           => ['abs_rate' => 0.0, 'timeoff_taken' => 0, 'late_count' => 0, 'overtime_weeks' => 0],
            'widget_layout'      => ['order' => HomeController::ADMIN_WIDGETS, 'widths' => HomeController::ADMIN_WIDGET_WIDTHS],
        ])->render();

        $this->assertSame([['financial_overview', 'half'], ['hr_absenteeism', 'half']], self::items($html));
        $this->assertStringNotContainsString('dash-two-col', $html);
    }

    public function testEmployeeWidgetsFollowTheLayout(): void
    {
        $html = $this->view->make('employee.dashboard', [
            'BASE_URL'        => '',
            'shifts_today'    => [],
            'upcoming'        => [],
            'enabled_widgets' => array_flip(['shifts_today', 'upcoming']),
            'all_widgets'     => ['shifts_today', 'upcoming', 'monthly_stats', 'timeclock'],
            'bundle_widgets'  => [['key' => 'timeclock', 'html' => '<div class="card">pointage</div>']],
            'widget_layout'   => ['order' => ['timeclock', 'upcoming', 'shifts_today', 'monthly_stats'], 'widths' => ['upcoming' => 'half']],
        ])->render();

        $this->assertSame([['timeclock', 'full'], ['upcoming', 'half'], ['shifts_today', 'full']], self::items($html));
    }

    /** Grille 12 colonnes : largeur et hauteur sont des classes, jamais un style en ligne ; repli et densité aussi. */
    public function testWidgetSizeFoldingAndDensityAreRenderedAsClasses(): void
    {
        $html = $this->view->make('employee.dashboard', [
            'BASE_URL'        => '',
            'shifts_today'    => [],
            'upcoming'        => [],
            'enabled_widgets' => array_flip(['shifts_today', 'upcoming']),
            'all_widgets'     => ['shifts_today', 'upcoming', 'timeclock'],
            'bundle_widgets'  => [['key' => 'timeclock', 'html' => '<div class="card">pointage</div>']],
            'widget_limits'   => ['timeclock' => [3, 8]],
            'widget_layout'   => [
                'order'     => ['timeclock', 'upcoming', 'shifts_today'],
                'widths'    => ['timeclock' => 12, 'upcoming' => 4, 'shifts_today' => 'half'],
                'heights'   => ['upcoming' => 3, 'timeclock' => 1, 'shifts_today' => 1],
                'collapsed' => ['shifts_today'],
                'density'   => 'compact',
            ],
        ])->render();

        $this->assertStringContainsString('class="dash-grid dash-grid--compact"', $html);
        $this->assertSame([['timeclock', '8'], ['upcoming', '4'], ['shifts_today', 'half']], self::items($html), 'la largeur est bornée par le widget (3–8)');
        $this->assertMatchesRegularExpression('/id="dash-widget-upcoming"\s+class="dash-grid__item dash-w-4 dash-h-3"/', $html);
        $this->assertMatchesRegularExpression('/id="dash-widget-timeclock"\s+class="dash-grid__item dash-w-8 dash-h-1"/', $html);
        $this->assertMatchesRegularExpression('/id="dash-widget-shifts_today"\s+class="dash-grid__item dash-w-6 dash-h-1 dash-grid__item--collapsed"/', $html);
        $this->assertStringContainsString('data-widget-min="3" data-widget-max="8"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html, 'le widget replié propose de le déplier');
        $this->assertStringNotContainsString(' style="', $html);
    }

    /** Une ancienne disposition (« half » / « full » seulement) s'affiche comme avant. */
    public function testOldHalfAndFullLayoutsRenderAsSixAndTwelveColumns(): void
    {
        $html = $this->view->make('employee.dashboard', [
            'BASE_URL'        => '',
            'shifts_today'    => [],
            'upcoming'        => [],
            'enabled_widgets' => array_flip(['shifts_today', 'upcoming']),
            'all_widgets'     => ['shifts_today', 'upcoming'],
            'bundle_widgets'  => [],
            'widget_layout'   => ['order' => ['upcoming', 'shifts_today'], 'widths' => ['upcoming' => 'half']],
        ])->render();

        $this->assertStringContainsString('dash-w-6 dash-h-1', $html);
        $this->assertStringContainsString('dash-w-12 dash-h-1', $html);
        $this->assertStringNotContainsString('dash-grid--compact', $html);
    }

    /** Rafraîchissement : intervalle par widget (Core par défaut, bundle déclaré), actions d'en-tête et mode mural. */
    public function testRefreshIntervalsAndHeaderActionsAreRendered(): void
    {
        $html = $this->view->make('employee.dashboard', [
            'BASE_URL'        => '',
            'shifts_today'    => [],
            'upcoming'        => [],
            'enabled_widgets' => array_flip(['shifts_today', 'upcoming', 'monthly_stats']),
            'all_widgets'     => ['shifts_today', 'upcoming', 'monthly_stats', 'timeclock'],
            'bundle_widgets'  => [['key' => 'timeclock', 'html' => '<div class="card">pointage</div>']],
            'widget_refresh'  => ['timeclock' => 45],
            'widget_layout'   => ['order' => ['shifts_today', 'monthly_stats', 'timeclock'], 'widths' => []],
        ])->render();

        $this->assertMatchesRegularExpression('/data-widget-key="shifts_today"[^>]*data-widget-refresh="120"/s', $html);
        $this->assertMatchesRegularExpression('/data-widget-key="timeclock"[^>]*data-widget-refresh="45"/s', $html);
        $this->assertStringContainsString('data-dash-refresh-now', $html);
        $this->assertStringContainsString('data-dash-wall', $html);
        $this->assertStringContainsString('data-dash-wall-exit', $html);
        $this->assertStringContainsString('dashboard-live.js', $html);
    }
}
