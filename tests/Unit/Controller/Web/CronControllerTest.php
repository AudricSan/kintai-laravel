<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Cron\CronJobInterface;
use App\Cron\CronRunner;
use App\Exceptions\ForbiddenException;
use App\Models\CronToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\CronController;
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
    private ?CronController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(1);
    }

    /** Contrôleur construit à la première utilisation : un test peut d'abord remplacer le bouchon des jetons par un mock. */
    private function controller(): CronController
    {
        if ($this->controller !== null) {
            return $this->controller;
        }
        $runner = new CronRunner();

        $stubJob = new class implements CronJobInterface {
            public function getName(): string
            {
                return 'stub-job';
            }

            public function run(Request $request): Response
            {
                return response()->json(['ok' => true, 'ran' => $this->getName()]);
            }
        };
        $runner->register($stubJob);

        return $this->controller = new CronController($runner);
    }

    public function testRunDispatchesToTheRegisteredJobWithAValidToken(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => 'stub-job', 'token' => hash_token('raw-token')]);

        $_GET['token'] = 'raw-token';
        $req = Request::capture();
        $req->setRouteParams(['job' => 'stub-job']);

        $response = $this->controller()->run($req);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRunRejectsMissingToken(): void
    {
        $req = Request::capture();
        $req->setRouteParams(['job' => 'stub-job']);
        $_GET = [];

        $this->expectException(ForbiddenException::class);
        $this->controller()->run($req);
    }

    public function testRunRejectsTokenScopedToADifferentJob(): void
    {
        CronToken::create(['user_id' => 1, 'job_name' => 'backup', 'token' => hash_token('raw-token')]);

        $_GET['token'] = 'raw-token';
        $req = Request::capture();
        $req->setRouteParams(['job' => 'stub-job']);

        $this->expectException(ForbiddenException::class);
        $this->controller()->run($req);
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }
}
