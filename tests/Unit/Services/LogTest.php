<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\Log;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\DatabaseLogRepository;
use Illuminate\Database\Capsule\Manager as Capsule;

final class LogTest extends TestCase
{
    private LogRepositoryInterface $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('activity_log', function ($table) {
            $table->increments('id');
            $table->string('level', 20)->default('info');
            $table->string('channel', 50)->default('business');
            $table->text('message')->nullable();
            $table->string('action', 150)->nullable();
            $table->string('resource_type', 50)->nullable();
            $table->integer('resource_id')->nullable();
            $table->text('context')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('store_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_uri', 500)->nullable();
            $table->integer('response_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->repo = new DatabaseLogRepository();

        // Use reflection to inject repo directly into Log facade
        $ref = new \ReflectionProperty(Log::class, 'repo');
        $ref->setAccessible(true);
        $ref->setValue(null, $this->repo);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(Log::class, 'repo');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    public function testDebug(): void
    {
        Log::debug('debug msg');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('business', $entry['channel']);
        $this->assertSame('debug msg', $entry['message']);
    }

    public function testInfo(): void
    {
        Log::info('info msg');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('info', $entry['level']);
    }

    public function testWarning(): void
    {
        Log::warning('warn msg');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('warning', $entry['level']);
    }

    public function testError(): void
    {
        Log::error('err msg');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('error', $entry['level']);
    }

    public function testCritical(): void
    {
        Log::critical('crit msg');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('critical', $entry['level']);
    }

    public function testAudit(): void
    {
        Log::audit('shift.created');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('audit', $entry['channel']);
    }

    public function testAccess(): void
    {
        Log::access('GET / -> 200');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('access', $entry['channel']);
    }

    public function testBusiness(): void
    {
        Log::business('business msg');
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('business', $entry['channel']);
    }

    public function testRecord(): void
    {
        Log::record('shift.created', 'shift', 42, 'Shift created', ['key' => 'val'], 1, 2);
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('audit', $entry['channel']);
        $this->assertSame('shift.created', $entry['action']);
        $this->assertSame('shift', $entry['resource_type']);
        $this->assertSame(42, $entry['resource_id']);
        $this->assertSame('Shift created', $entry['message']);
        $this->assertSame(1, $entry['user_id']);
        $this->assertSame(2, $entry['store_id']);
    }

    public function testWriteWithDurationAndStatus(): void
    {
        Log::write('info', 'access', 'GET / -> 200', [], null, null, 200, 42);
        $entry = $this->repo->findAll(1, 10)[0];
        $this->assertSame(200, $entry['response_status']);
        $this->assertSame(42, $entry['duration_ms']);
    }

    public function testRecordDoesNotThrow(): void
    {
        Log::record('test.action', 'test', 1, 'test');
        $this->assertTrue(true);
    }
}
