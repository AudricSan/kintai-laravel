<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\DatabaseUserDashboardPrefsRepository;
use PHPUnit\Framework\TestCase;

/** Disposition des tableaux de bord (user_dashboard_prefs.layout) : enregistrement indépendant des widgets activés. */
final class DashboardLayoutPrefsTest extends TestCase
{
    private DatabaseUserDashboardPrefsRepository $prefs;
    private int $userId;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner, 'capsule'))->setValue($runner, $capsule);
        (new \ReflectionProperty($runner, 'migrationsPath'))->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');
        $runner->run();

        $conn = $capsule->getConnection();
        $conn->table('users')->insert(['email' => 'layout@kintai.test', 'password_hash' => 'x', 'first_name' => 'L', 'last_name' => 'Y', 'display_name' => 'L Y']);
        $this->userId = (int) $conn->table('users')->where('email', 'layout@kintai.test')->value('id');
        $this->prefs = new DatabaseUserDashboardPrefsRepository();
    }

    public function testSavingALayoutFirstKeepsTheDefaultWidgetSelection(): void
    {
        $this->prefs->saveLayout($this->userId, 'admin', ['order' => ['shifts_today', 'kpi_counters'], 'widths' => ['shifts_today' => 'half']]);

        $this->assertNull($this->prefs->getEnabledWidgets($this->userId, 'admin'));
        $this->assertSame(['order' => ['shifts_today', 'kpi_counters'], 'widths' => ['shifts_today' => 'half']], $this->prefs->getLayout($this->userId, 'admin'));
        $this->assertNull($this->prefs->getLayout($this->userId, 'employee'));
    }

    public function testWidgetsAndLayoutAreSavedIndependently(): void
    {
        $this->prefs->saveWidgets($this->userId, ['kpi_counters'], 'admin');
        $this->prefs->saveLayout($this->userId, 'admin', ['order' => ['kpi_counters'], 'widths' => []]);
        $this->prefs->saveWidgets($this->userId, ['kpi_counters', 'shifts_today'], 'admin');

        $this->assertSame(['kpi_counters', 'shifts_today'], $this->prefs->getEnabledWidgets($this->userId, 'admin'));
        $this->assertSame(['order' => ['kpi_counters'], 'widths' => []], $this->prefs->getLayout($this->userId, 'admin'));

        $this->prefs->saveLayout($this->userId, 'admin', null);

        $this->assertNull($this->prefs->getLayout($this->userId, 'admin'));
        $this->assertSame(['kpi_counters', 'shifts_today'], $this->prefs->getEnabledWidgets($this->userId, 'admin'));
    }
}
