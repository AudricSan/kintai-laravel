<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Repositories\DatabaseLogRepository;
use App\Repositories\LogRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Http\Controllers\System\ActivityController;
use App\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

/** Purge manuelle du journal d'activité : par ancienneté ou totale, sur une vraie base SQLite en mémoire. */
final class ActivityLogPurgeTest extends TestCase
{
    private Capsule $capsule;
    private DatabaseLogRepository $logs;

    protected function setUp(): void
    {
        $this->capsule = kintai_test_database();

        kintai_test_migrate();

        $this->logs = new DatabaseLogRepository();
        foreach (['-400 days', '-100 days', '-10 days', '-1 day'] as $age) {
            $this->logs->record(LogRepositoryInterface::LEVEL_INFO, LogRepositoryInterface::CHANNEL_AUDIT, 'x', 'test.action');
            $id = (int) $this->capsule->getConnection()->table('activity_log')->max('id');
            $this->capsule->getConnection()->table('activity_log')->where('id', $id)->update(['created_at' => date('Y-m-d H:i:s', strtotime($age))]);
        }
    }

    private function rowCount(): int
    {
        // Les entrées de test seulement : la purge écrit aussi sa propre entrée d'audit.
        return (int) $this->capsule->getConnection()->table('activity_log')->where('action', 'test.action')->count();
    }

    private function controller(): ActivityController
    {
        $users = $this->createStub(UserRepositoryInterface::class);

        return new ActivityController(new ViewRenderer(sys_get_temp_dir()), $this->logs, $users, new AuditLogger());
    }

    private function purgeRequest(string $keepDays): Request
    {
        $_POST = ['keep_days' => $keepDays];
        $request = Request::capture();
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

        // Deux entrées de plus de 30 jours supprimées.
        $this->assertSame(2, $this->rowCount());
        $this->assertStringContainsString('purged=2', ($response->headers->get('Location') ?? ''));
    }

    public function testControllerDeletesEverythingWhenKeepIsZero(): void
    {
        $response = $this->controller()->purge($this->purgeRequest('0'));

        $this->assertSame(0, $this->rowCount());
        $this->assertStringContainsString('purged=4', ($response->headers->get('Location') ?? ''));
    }

    public function testControllerRefusesAnUnknownChoiceAndDeletesNothing(): void
    {
        foreach (['', 'abc', '-1', '15'] as $bad) {
            $response = $this->controller()->purge($this->purgeRequest($bad));

            $this->assertStringContainsString('purge_error=1', ($response->headers->get('Location') ?? ''));
        }
        $this->assertSame(4, $this->rowCount());
    }
}
