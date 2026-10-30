<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Models\UserDashboardPref;
use App\Exceptions\ValidationException;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Http\Controllers\DashboardLayoutController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutControllerTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    private DashboardLayoutController $controller;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(7);
        $this->controller = new DashboardLayoutController(new AuditLogger());
    }

    private function request(array $body): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 7]);
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
        return $req;
    }

    public function testManagerLayoutIsMergedIntoTheSavedOne(): void
    {
        // dashboard_alerts, store_stats_summary… (et les widgets employé « emp_ ») ne sont pas affichés : ils gardent leur place.
        $this->seedLayout(7, 'admin', null);
        $this->expectLayout(7, 'admin', [ 'order' => ['shifts_today', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'hr_absenteeism', 'kpi_counters', 'emp_shifts_today', 'emp_upcoming', 'emp_monthly_stats'], 'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'full'], ]);

        $response = $this->controller->saveManager($this->request([
            'order'  => ['shifts_today', 'kpi_counters'],
            'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'full'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEmployeeLayoutGoesToTheEmployeeDashboard(): void
    {
        $this->expectLayout(7, 'employee', ['order' => ['upcoming', 'shifts_today', 'monthly_stats'], 'widths' => []]);

        $this->controller->saveEmployee($this->request(['order' => ['upcoming', 'shifts_today', 'monthly_stats']]));
    }

    public function testResetForgetsTheLayout(): void
    {
        $this->expectLayout(7, 'admin', null);

        $response = $this->controller->saveManager($this->request(['reset' => true]));

        $this->assertSame(['layout' => null], json_decode($response->getContent(), true));
    }

    public function testAddedWidgetGoesToTheEndWithoutResettingTheOthers(): void
    {
        // shifts_today était masqué ; les autres widgets gardent leur ordre et leur largeur.
        $saved = ['order' => ['hr_absenteeism', 'kpi_counters'], 'widths' => ['hr_absenteeism' => 'full', 'kpi_counters' => 'half']];
        $this->seedWidgets(7, 'admin', ['kpi_counters', 'hr_absenteeism']);
        $this->seedLayout(7, 'admin', $saved);
        $this->expectWidgets(7, ['kpi_counters', 'hr_absenteeism', 'shifts_today'], 'admin');
        $this->expectLayout(7, 'admin', [ 'order' => ['hr_absenteeism', 'kpi_counters', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'emp_shifts_today', 'emp_upcoming', 'emp_monthly_stats', 'shifts_today'], 'widths' => $saved['widths'], ]);

        $response = $this->controller->widgetsManager($this->request(['add' => 'shifts_today']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testManagerCanAddHisEmployeeWidgets(): void
    {
        // Sans préférences : les widgets du gérant sont affichés par défaut, pas ses widgets employé.
        $this->expectWidgets(7, ['kpi_counters', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'hr_absenteeism', 'shifts_today', 'emp_upcoming'], 'admin');

        $this->controller->widgetsManager($this->request(['add' => 'emp_upcoming']));
    }

    public function testEmployeeCannotAddManagerWidgets(): void
    {
        $this->expectNoPrefsWrite();

        foreach (['kpi_counters', 'emp_upcoming'] as $key) {
            try {
                $this->controller->widgetsEmployee($this->request(['add' => $key]));
                $this->fail("« {$key} » ne doit pas être proposé côté employé");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRemovedWidgetLeavesTheLayoutUntouched(): void
    {
        // Sans préférences : tous les widgets du gérant sont affichés, on en retire un.
        $this->expectNoPrefsWrite();
        $this->expectWidgets(7, ['kpi_counters', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'hr_absenteeism'], 'admin');

        $this->controller->widgetsManager($this->request(['remove' => 'shifts_today']));
    }

    public function testEmployeeWidgetsGoToTheEmployeeDashboard(): void
    {
        $this->seedWidgets(7, 'employee', ['upcoming']);
        $this->expectWidgets(7, [], 'employee');

        $this->controller->widgetsEmployee($this->request(['remove' => 'upcoming']));
    }

    public function testUnknownWidgetIsRejected(): void
    {
        $this->expectNoPrefsWrite();
        $this->expectException(ValidationException::class);

        $this->controller->widgetsManager($this->request(['add' => 'not_a_widget']));
    }

    /** Hauteurs, widgets repliés et densité envoyés par la page sont enregistrés avec l'ordre et les largeurs. */
    public function testSizeFoldingAndDensityAreSaved(): void
    {
        $this->seedLayout(7, 'employee', null);

        $this->controller->saveEmployee($this->request([
            'order'     => ['shifts_today', 'upcoming'],
            'widths'    => ['shifts_today' => 4, 'upcoming' => 8],
            'heights'   => ['shifts_today' => 2],
            'collapsed' => ['upcoming'],
            'density'   => 'compact',
        ]));

        $saved = \App\Models\UserDashboardPref::getLayout(7, 'employee');
        $this->assertSame(4, $saved['widths']['shifts_today']);
        $this->assertSame(8, $saved['widths']['upcoming']);
        $this->assertSame(['shifts_today' => 2], $saved['heights']);
        $this->assertSame(['upcoming'], $saved['collapsed']);
        $this->assertSame('compact', $saved['density']);
    }

    public function testOutOfRangeSizesAreIgnored(): void
    {

        $this->controller->saveEmployee($this->request([
            'order'   => ['shifts_today'],
            'widths'  => ['shifts_today' => 40],
            'heights' => ['shifts_today' => 99],
        ]));

        $saved = \App\Models\UserDashboardPref::getLayout(7, 'employee');
        $this->assertArrayNotHasKey('shifts_today', $saved['widths']);
        $this->assertArrayNotHasKey('heights', $saved);
    }
}
