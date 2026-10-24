<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\DatabaseLogRepository;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Web\System\ActivityController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/** Purge manuelle du journal d'activité : par ancienneté ou totale, sur une vraie base SQLite en mémoire. */
final class ActivityLogPurgeTest extends TestCase
{
    private Capsule $capsule;
    private DatabaseLogRepository $logs;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner, 'capsule'))->setValue($runner, $this->capsule);
        (new \ReflectionProperty($runner, 'migrationsPath'))->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');
        $runner->run();

        $this->logs = new DatabaseLogRepository();
        foreach (['-400 days', '-100 days', '-10 days', '-1 day'] as $age) {
            $this->logs->record(LogRepositoryInterface::LEVEL_INFO, LogRepositoryInterface::CHANNEL_AUDIT, 'x', 'test.action');
            $id = (int) $this->capsule->getConnection()->table('activity_log')->max('id');
            $this->capsule->getConnection()->table('activity_log')->where('id', $id)->update(['created_at' => date('Y-m-d H:i:s', strtotime($age))]);
        }
    }

    private function rowCount(): int
    {
        return (int) $this->capsule->getConnection()->table('activity_log')->count();
    }

    private function controller(): ActivityController
    {
        $users = $this->createStub(UserRepositoryInterface::class);

        return new ActivityController(new ViewRenderer(sys_get_temp_dir()), $this->logs, $users, new AuditLogger());
    }

    private function purgeRequest(string $keepDays): Request
    {
        $_POST = ['keep_days' => $keepDays];
        $request = new Request();
        $_POST = [];

        return $request;
    }

    public function testPurgeAllEmptiesTheLog(): void
    {
        $this->assertSame(4, $this->logs->purgeAll());
        $this->assertSame(0, $this->rowCount());
    }

    public function testReclaimSpaceDoesNotFailAndKeepsTheRows(): void
    {
        $this->logs->reclaimSpace();

        $this->assertSame(4, $this->rowCount());
    }

    public function testControllerKeepsTheRequestedNumberOfDays(): void
    {
        $response = $this->controller()->purge($this->purgeRequest('30'));

        // Deux entrées de plus de 30 jours supprimées ; Log n'est pas branché ici, donc pas d'entrée d'audit.
        $this->assertSame(2, $this->rowCount());
        $this->assertStringContainsString('purged=2', ($response->headers()['Location'] ?? ''));
    }

    public function testControllerDeletesEverythingWhenKeepIsZero(): void
    {
        $response = $this->controller()->purge($this->purgeRequest('0'));

        $this->assertSame(0, $this->rowCount());
        $this->assertStringContainsString('purged=4', ($response->headers()['Location'] ?? ''));
    }

    public function testControllerRefusesAnUnknownChoiceAndDeletesNothing(): void
    {
        foreach (['', 'abc', '-1', '15'] as $bad) {
            $response = $this->controller()->purge($this->purgeRequest($bad));

            $this->assertStringContainsString('purge_error=1', ($response->headers()['Location'] ?? ''));
        }
        $this->assertSame(4, $this->rowCount());
    }
}
