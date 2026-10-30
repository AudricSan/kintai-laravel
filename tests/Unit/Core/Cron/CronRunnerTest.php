<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Cron;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Illuminate\Container\Container;
use App\Cron\CronJobInterface;
use App\Cron\CronRunner;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\CronToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\TranslationRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Translation\Translator;

final class CronRunnerTest extends TestCase
{
    private CronJobInterface&Stub $sampleJob;
    private ?CronRunner $runner = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(1);
        $this->sampleJob  = $this->createStub(CronJobInterface::class);
        $this->sampleJob->method('getName')->willReturn('test-job');
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function runner(): CronRunner
    {
        if ($this->runner === null) {
            $this->runner = new CronRunner();
            $this->runner->register($this->sampleJob);
        }

        return $this->runner;
    }

    // -------------------------------------------------------------------------
    // register() / getJob() / getJobs() / getJobMeta()
    // -------------------------------------------------------------------------

    public function testGetJobReturnsRegisteredJob(): void
    {
        $this->assertSame($this->sampleJob, $this->runner()->getJob('test-job'));
    }

    public function testGetJobReturnsNullForUnknownJob(): void
    {
        $this->assertNull($this->runner()->getJob('nope'));
    }

    public function testGetJobsReturnsAllRegisteredJobs(): void
    {
        $this->assertCount(1, $this->runner()->getJobs());
        $this->assertArrayHasKey('test-job', $this->runner()->getJobs());
    }

    public function testGetJobMetaReturnsStructuredArray(): void
    {
        $meta = $this->runner()->getJobMeta();
        $this->assertCount(1, $meta);
        $this->assertSame('test-job', $meta[0]['name']);
        $this->assertNotEmpty($meta[0]['label']);
    }

    public function testJobLabelComesFromTheTranslationConventionOrFallsBackToTheName(): void
    {
        try {
            kintai_test_translator(['fr' => ['cron_job_auto_validate' => 'Validation automatique']], 'fr');

            $this->assertSame('Validation automatique', CronRunner::label('auto-validate'));
            $this->assertSame('photo-retention', CronRunner::label('photo-retention'));
        } finally {
            kintai_test_translator_reset();
        }
    }

    // -------------------------------------------------------------------------
    // run() — validation du token
    // -------------------------------------------------------------------------

    public function testRunWithValidQueryTokenExecutesJob(): void
    {
        $this->sampleJob = $this->createMock(CronJobInterface::class);
        $this->sampleJob->method('getName')->willReturn('test-job');
        $req = $this->requestWithQuery('valid-raw-token');
        $token = CronToken::create(['user_id' => 1, 'job_name' => 'test-job', 'token' => hash_token('valid-raw-token'), 'expires_at' => null]);
        $this->sampleJob->expects($this->once())->method('run')->with($req)->willReturn(response()->json(['ok' => true]));

        $response = $this->runner()->run('test-job', $req);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($token->fresh()->last_used_at, 'dernier usage horodaté');
    }

    public function testRunWithValidBearerTokenExecutesJob(): void
    {
        $req = $this->requestWithBearer('valid-bearer-token');
        CronToken::create(['user_id' => 1, 'job_name' => 'test-job', 'token' => hash_token('valid-bearer-token'), 'expires_at' => null]);
        $this->sampleJob->method('run')->willReturn(response()->json(['ok' => true]));

        $response = $this->runner()->run('test-job', $req);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRunThrowsForbiddenWhenNoToken(): void
    {
        $req = $this->makeCleanRequest();

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cron_token_missing');
        $this->runner()->run('test-job', $req);
    }

    public function testRunThrowsForbiddenWhenTokenNotFound(): void
    {
        $req = $this->requestWithQuery('unknown-token');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cron_token_invalid');
        $this->runner()->run('test-job', $req);
    }

    public function testRunThrowsForbiddenWhenTokenExpired(): void
    {
        $req = $this->requestWithQuery('expired-token');

        CronToken::create(['user_id' => 1, 'job_name' => 'test-job', 'token' => hash_token('expired-token'), 'expires_at' => '2020-01-01 00:00:00']);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cron_token_expired');
        $this->runner()->run('test-job', $req);
    }

    public function testRunThrowsNotFoundWhenJobUnknown(): void
    {
        $req = $this->requestWithQuery('some-token');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('error_cron_unknown_job');
        $this->runner()->run('unknown-job', $req);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCleanRequest(): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        return Request::capture();
    }

    private function requestWithQuery(string $token): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/cron/run/test?token=$token", 'SCRIPT_NAME' => '/index.php'];
        $_GET    = ['token' => $token];
        $_POST   = $_COOKIE = $_FILES = [];
        return Request::capture();
    }

    // -------------------------------------------------------------------------
    // runLocal() — ligne de commande (kintai:cron), sans jeton
    // -------------------------------------------------------------------------

    public function testRunLocalExecutesJobWithoutToken(): void
    {
        $this->sampleJob = $this->createMock(CronJobInterface::class);
        $this->sampleJob->method('getName')->willReturn('test-job');
        $expected = response()->json(['ok' => true]);
        $this->sampleJob->expects($this->once())->method('run')->willReturn($expected);

        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/cron/run/test-job', 'SCRIPT_NAME' => '/index.php'];
        $_GET = ['date' => '2026-10-13'];

        $this->assertSame($expected, $this->runner()->runLocal('test-job', Request::capture()));
    }

    public function testRunLocalThrowsNotFoundWhenJobUnknown(): void
    {
        $this->expectException(NotFoundException::class);
        $this->runner()->runLocal('nope', $this->requestWithBearer('x'));
    }

    private function requestWithBearer(string $token): Request
    {
        $_SERVER = [
            'REQUEST_METHOD'           => 'GET',
            'REQUEST_URI'              => '/api/v1/cron/run/test',
            'SCRIPT_NAME'              => '/index.php',
            'HTTP_AUTHORIZATION'       => "Bearer $token",
        ];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        return Request::capture();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }
}
