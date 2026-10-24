<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Cron;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Container;
use kintai\Core\Cron\CronJobInterface;
use kintai\Core\Cron\CronRunner;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\CronTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\TranslationService;

final class CronRunnerTest extends TestCase
{
    private CronTokenRepositoryInterface&Stub $cronTokens;
    private CronJobInterface&Stub $sampleJob;
    private ?CronRunner $runner = null;

    protected function setUp(): void
    {
        $this->cronTokens = $this->createStub(CronTokenRepositoryInterface::class);
        $this->sampleJob  = $this->createStub(CronJobInterface::class);
        $this->sampleJob->method('getName')->willReturn('test-job');
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function runner(): CronRunner
    {
        if ($this->runner === null) {
            $this->runner = new CronRunner($this->cronTokens);
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
        $prop = new \ReflectionProperty(Container::class, 'instance');
        $previous = $prop->getValue();
        $prop->setValue(null, null);
        try {
            $translations = $this->createStub(TranslationRepositoryInterface::class);
            $translations->method('findByLocale')->willReturn(['cron_job_auto_validate' => 'Validation automatique']);
            $languages = $this->createStub(LanguageRepositoryInterface::class);
            $languages->method('findDefault')->willReturn(['code' => 'fr']);
            $service = new TranslationService($translations, $languages);
            $service->setLocale('fr');
            Container::getInstance()->instance(TranslationService::class, $service);

            $this->assertSame('Validation automatique', CronRunner::label('auto-validate'));
            $this->assertSame('photo-retention', CronRunner::label('photo-retention'));
        } finally {
            $prop->setValue(null, $previous);
        }
    }

    // -------------------------------------------------------------------------
    // run() — validation du token
    // -------------------------------------------------------------------------

    public function testRunWithValidQueryTokenExecutesJob(): void
    {
        $this->cronTokens = $this->createMock(CronTokenRepositoryInterface::class);
        $this->sampleJob = $this->createMock(CronJobInterface::class);
        $this->sampleJob->method('getName')->willReturn('test-job');
        $req = $this->requestWithQuery('valid-raw-token');

        $this->cronTokens->method('findByToken')
            ->willReturnCallback(fn(string $h) => hash_equals(hash_token('valid-raw-token'), $h)
                ? ['id' => 1, 'user_id' => 1, 'job_name' => 'test-job', 'token' => $h, 'expires_at' => null]
                : null);

        $this->cronTokens->expects($this->once())->method('touchLastUsed')->with(1);
        $this->sampleJob->expects($this->once())->method('run')->with($req)->willReturn(Response::json(['ok' => true]));

        $response = $this->runner()->run('test-job', $req);
        $this->assertSame(200, $response->status());
    }

    public function testRunWithValidBearerTokenExecutesJob(): void
    {
        $this->cronTokens = $this->createMock(CronTokenRepositoryInterface::class);
        $req = $this->requestWithBearer('valid-bearer-token');

        $this->cronTokens->method('findByToken')
            ->willReturnCallback(fn(string $h) => hash_equals(hash_token('valid-bearer-token'), $h)
                ? ['id' => 2, 'user_id' => 1, 'job_name' => 'test-job', 'token' => $h, 'expires_at' => null]
                : null);

        $this->cronTokens->expects($this->once())->method('touchLastUsed')->with(2);
        $this->sampleJob->method('run')->willReturn(Response::json(['ok' => true]));

        $response = $this->runner()->run('test-job', $req);
        $this->assertSame(200, $response->status());
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

        $this->cronTokens->method('findByToken')->willReturn(null);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cron_token_invalid');
        $this->runner()->run('test-job', $req);
    }

    public function testRunThrowsForbiddenWhenTokenExpired(): void
    {
        $req = $this->requestWithQuery('expired-token');

        $this->cronTokens->method('findByToken')
            ->willReturnCallback(fn(string $h) => hash_equals(hash_token('expired-token'), $h)
                ? ['id' => 3, 'user_id' => 1, 'job_name' => 'test-job', 'token' => $h, 'expires_at' => '2020-01-01 00:00:00']
                : null);

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
        return new Request();
    }

    private function requestWithQuery(string $token): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/cron/run/test?token=$token", 'SCRIPT_NAME' => '/index.php'];
        $_GET    = ['token' => $token];
        $_POST   = $_COOKIE = $_FILES = [];
        return new Request();
    }

    // -------------------------------------------------------------------------
    // runLocal() — ligne de commande (scripts/cron.php), sans jeton
    // -------------------------------------------------------------------------

    public function testRunLocalExecutesJobWithoutToken(): void
    {
        $this->cronTokens = $this->createMock(CronTokenRepositoryInterface::class);
        $this->sampleJob = $this->createMock(CronJobInterface::class);
        $this->sampleJob->method('getName')->willReturn('test-job');
        $expected = Response::json(['ok' => true]);
        $this->sampleJob->expects($this->once())->method('run')->willReturn($expected);
        $this->cronTokens->expects($this->never())->method('findByToken');

        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/cron/run/test-job', 'SCRIPT_NAME' => '/index.php'];
        $_GET = ['date' => '2026-10-13'];

        $this->assertSame($expected, $this->runner()->runLocal('test-job', new Request()));
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
        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }
}
