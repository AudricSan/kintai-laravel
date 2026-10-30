<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\UserDashboardPref;
use PHPUnit\Framework\TestCase;

/** Disposition des tableaux de bord (user_dashboard_prefs.layout) : enregistrement indépendant des widgets activés. */
final class DashboardLayoutPrefsTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        $capsule = kintai_test_database();

        kintai_test_migrate();

        $conn = $capsule->getConnection();
        $conn->table('users')->insert(['email' => 'layout@kintai.test', 'password_hash' => 'x', 'first_name' => 'L', 'last_name' => 'Y', 'display_name' => 'L Y']);
        $this->userId = (int) $conn->table('users')->where('email', 'layout@kintai.test')->value('id');
    }

    public function testSavingALayoutFirstKeepsTheDefaultWidgetSelection(): void
    {
        UserDashboardPref::saveLayout($this->userId, 'admin', ['order' => ['shifts_today', 'kpi_counters'], 'widths' => ['shifts_today' => 'half']]);

        $this->assertNull(UserDashboardPref::getEnabledWidgets($this->userId, 'admin'));
        $this->assertSame(['order' => ['shifts_today', 'kpi_counters'], 'widths' => ['shifts_today' => 'half']], UserDashboardPref::getLayout($this->userId, 'admin'));
        $this->assertNull(UserDashboardPref::getLayout($this->userId, 'employee'));
    }

    public function testWidgetsAndLayoutAreSavedIndependently(): void
    {
        UserDashboardPref::saveWidgets($this->userId, ['kpi_counters'], 'admin');
        UserDashboardPref::saveLayout($this->userId, 'admin', ['order' => ['kpi_counters'], 'widths' => []]);
        UserDashboardPref::saveWidgets($this->userId, ['kpi_counters', 'shifts_today'], 'admin');

        $this->assertSame(['kpi_counters', 'shifts_today'], UserDashboardPref::getEnabledWidgets($this->userId, 'admin'));
        $this->assertSame(['order' => ['kpi_counters'], 'widths' => []], UserDashboardPref::getLayout($this->userId, 'admin'));

        UserDashboardPref::saveLayout($this->userId, 'admin', null);

        $this->assertNull(UserDashboardPref::getLayout($this->userId, 'admin'));
        $this->assertSame(['kpi_counters', 'shifts_today'], UserDashboardPref::getEnabledWidgets($this->userId, 'admin'));
    }
}
