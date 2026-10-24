<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\DashboardLayoutController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutControllerTest extends TestCase
{
    private UserDashboardPrefsRepositoryInterface&MockObject $prefs;
    private DashboardLayoutController $controller;

    protected function setUp(): void
    {
        $this->prefs = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->controller = new DashboardLayoutController($this->prefs, new AuditLogger());
    }

    private function request(array $body): Request
    {
        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 7]);
        (new \ReflectionProperty(Request::class, 'jsonBody'))->setValue($req, $body);
        return $req;
    }

    public function testManagerLayoutIsMergedIntoTheSavedOne(): void
    {
        // dashboard_alerts, store_stats_summary… (et les widgets employé « emp_ ») ne sont pas affichés : ils gardent leur place.
        $this->prefs->method('getLayout')->with(7, 'admin')->willReturn(null);
        $this->prefs->expects($this->once())->method('saveLayout')->with(7, 'admin', [
            'order'  => ['shifts_today', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'hr_absenteeism', 'kpi_counters',
                         'emp_shifts_today', 'emp_upcoming', 'emp_monthly_stats'],
            'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'full'],
        ]);

        $response = $this->controller->saveManager($this->request([
            'order'  => ['shifts_today', 'kpi_counters'],
            'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'full'],
        ]));

        $this->assertSame(200, $response->status());
    }

    public function testEmployeeLayoutGoesToTheEmployeeDashboard(): void
    {
        $this->prefs->method('getLayout')->willReturn(null);
        $this->prefs->expects($this->once())->method('saveLayout')
            ->with(7, 'employee', ['order' => ['upcoming', 'shifts_today', 'monthly_stats'], 'widths' => []]);

        $this->controller->saveEmployee($this->request(['order' => ['upcoming', 'shifts_today', 'monthly_stats']]));
    }

    public function testResetForgetsTheLayout(): void
    {
        $this->prefs->expects($this->never())->method('getLayout');
        $this->prefs->expects($this->once())->method('saveLayout')->with(7, 'admin', null);

        $response = $this->controller->saveManager($this->request(['reset' => true]));

        $this->assertSame(['layout' => null], json_decode($response->body(), true));
    }

    public function testAddedWidgetGoesToTheEndWithoutResettingTheOthers(): void
    {
        // shifts_today était masqué ; les autres widgets gardent leur ordre et leur largeur.
        $saved = ['order' => ['hr_absenteeism', 'kpi_counters'], 'widths' => ['hr_absenteeism' => 'full', 'kpi_counters' => 'half']];
        $this->prefs->method('getEnabledWidgets')->with(7, 'admin')->willReturn(['kpi_counters', 'hr_absenteeism']);
        $this->prefs->method('getLayout')->with(7, 'admin')->willReturn($saved);
        $this->prefs->expects($this->once())->method('saveWidgets')->with(7, ['kpi_counters', 'hr_absenteeism', 'shifts_today'], 'admin');
        $this->prefs->expects($this->once())->method('saveLayout')->with(7, 'admin', [
            'order'  => ['hr_absenteeism', 'kpi_counters', 'dashboard_alerts', 'store_stats_summary', 'financial_overview',
                         'emp_shifts_today', 'emp_upcoming', 'emp_monthly_stats', 'shifts_today'],
            'widths' => $saved['widths'],
        ]);

        $response = $this->controller->widgetsManager($this->request(['add' => 'shifts_today']));

        $this->assertSame(200, $response->status());
    }

    public function testManagerCanAddHisEmployeeWidgets(): void
    {
        // Sans préférences : les widgets du gérant sont affichés par défaut, pas ses widgets employé.
        $this->prefs->method('getEnabledWidgets')->willReturn(null);
        $this->prefs->method('getLayout')->willReturn(null);
        $this->prefs->expects($this->once())->method('saveWidgets')
            ->with(7, ['kpi_counters', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'hr_absenteeism', 'shifts_today', 'emp_upcoming'], 'admin');

        $this->controller->widgetsManager($this->request(['add' => 'emp_upcoming']));
    }

    public function testEmployeeCannotAddManagerWidgets(): void
    {
        $this->prefs->expects($this->never())->method('saveWidgets');

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
        $this->prefs->method('getEnabledWidgets')->willReturn(null);
        $this->prefs->expects($this->never())->method('saveLayout');
        $this->prefs->expects($this->once())->method('saveWidgets')
            ->with(7, ['kpi_counters', 'dashboard_alerts', 'store_stats_summary', 'financial_overview', 'hr_absenteeism'], 'admin');

        $this->controller->widgetsManager($this->request(['remove' => 'shifts_today']));
    }

    public function testEmployeeWidgetsGoToTheEmployeeDashboard(): void
    {
        $this->prefs->method('getEnabledWidgets')->with(7, 'employee')->willReturn(['upcoming']);
        $this->prefs->expects($this->once())->method('saveWidgets')->with(7, [], 'employee');

        $this->controller->widgetsEmployee($this->request(['remove' => 'upcoming']));
    }

    public function testUnknownWidgetIsRejected(): void
    {
        $this->prefs->expects($this->never())->method('saveWidgets');
        $this->expectException(ValidationException::class);

        $this->controller->widgetsManager($this->request(['add' => 'not_a_widget']));
    }

    /** Hauteurs, widgets repliés et densité envoyés par la page sont enregistrés avec l'ordre et les largeurs. */
    public function testSizeFoldingAndDensityAreSaved(): void
    {
        $this->prefs->method('getLayout')->with(7, 'employee')->willReturn(null);
        $saved = null;
        $this->prefs->expects($this->once())->method('saveLayout')->willReturnCallback(function (int $uid, string $type, ?array $layout) use (&$saved): void {
            $saved = $layout;
        });

        $this->controller->saveEmployee($this->request([
            'order'     => ['shifts_today', 'upcoming'],
            'widths'    => ['shifts_today' => 4, 'upcoming' => 8],
            'heights'   => ['shifts_today' => 2],
            'collapsed' => ['upcoming'],
            'density'   => 'compact',
        ]));

        $this->assertSame(4, $saved['widths']['shifts_today']);
        $this->assertSame(8, $saved['widths']['upcoming']);
        $this->assertSame(['shifts_today' => 2], $saved['heights']);
        $this->assertSame(['upcoming'], $saved['collapsed']);
        $this->assertSame('compact', $saved['density']);
    }

    public function testOutOfRangeSizesAreIgnored(): void
    {
        $this->prefs->method('getLayout')->willReturn(null);
        $saved = null;
        $this->prefs->method('saveLayout')->willReturnCallback(function (int $uid, string $type, ?array $layout) use (&$saved): void {
            $saved = $layout;
        });

        $this->controller->saveEmployee($this->request([
            'order'   => ['shifts_today'],
            'widths'  => ['shifts_today' => 40],
            'heights' => ['shifts_today' => 99],
        ]));

        $this->assertArrayNotHasKey('shifts_today', $saved['widths']);
        $this->assertArrayNotHasKey('heights', $saved);
    }
}
