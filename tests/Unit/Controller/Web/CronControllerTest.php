<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Cron\CronJobInterface;
use kintai\Core\Cron\CronRunner;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\CronTokenRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\Controller\Web\CronController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Régression : le job cron `backup` (CronRunner + BackupJob + cron_tokens) était
 * entièrement codé mais jamais branché à une route. Ces tests couvrent le nouveau
 * point d'entrée générique /cron/run/{job}.
 */
final class CronControllerTest extends TestCase
{
    private CronTokenRepositoryInterface&Stub $cronTokens;
    private ?CronController $controller = null;

    protected function setUp(): void
    {
        $this->cronTokens = $this->createStub(CronTokenRepositoryInterface::class);
    }

    /** Contrôleur construit à la première utilisation : un test peut d'abord remplacer le bouchon des jetons par un mock. */
    private function controller(): CronController
    {
        if ($this->controller !== null) {
            return $this->controller;
        }
        $runner = new CronRunner($this->cronTokens);

        $stubJob = new class implements CronJobInterface {
            public function getName(): string
            {
                return 'stub-job';
            }

            public function run(Request $request): Response
            {
                return Response::json(['ok' => true, 'ran' => $this->getName()]);
            }
        };
        $runner->register($stubJob);

        return $this->controller = new CronController($runner);
    }

    public function testRunDispatchesToTheRegisteredJobWithAValidToken(): void
    {
        $this->cronTokens = $this->createMock(CronTokenRepositoryInterface::class);
        $this->cronTokens->method('findByToken')->willReturn([
            'id' => 1, 'user_id' => 1, 'job_name' => 'stub-job', 'token' => 'hashed',
        ]);
        $this->cronTokens->expects($this->once())->method('touchLastUsed')->with(1);

        $_GET['token'] = 'raw-token';
        $req = new Request();
        $req->setRouteParams(['job' => 'stub-job']);

        $response = $this->controller()->run($req);

        $this->assertSame(200, $response->status());
    }

    public function testRunRejectsMissingToken(): void
    {
        $req = new Request();
        $req->setRouteParams(['job' => 'stub-job']);
        $_GET = [];

        $this->expectException(ForbiddenException::class);
        $this->controller()->run($req);
    }

    public function testRunRejectsTokenScopedToADifferentJob(): void
    {
        $this->cronTokens->method('findByToken')->willReturn([
            'id' => 1, 'user_id' => 1, 'job_name' => 'backup', 'token' => 'hashed',
        ]);

        $_GET['token'] = 'raw-token';
        $req = new Request();
        $req->setRouteParams(['job' => 'stub-job']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->run($req);
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }
}
