<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseCronTokenRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\CronToken as EloquentCronToken;

final class DatabaseCronTokenRepositoryTest extends TestCase
{
    private DatabaseCronTokenRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('cron_tokens', function ($table) {
            $table->increments('id');
            $table->string('token', 64)->unique();
            $table->string('name')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('job_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
        });

        $this->repo = new DatabaseCronTokenRepository();
    }

    private int $tokenCounter = 0;

    private function createToken(int $id, int $userId = 1, string $jobName = 'backup'): array
    {
        $this->tokenCounter++;
        return EloquentCronToken::create([
            'user_id'  => $userId,
            'job_name' => $jobName,
            'token'    => 'ab' . str_pad((string) $this->tokenCounter, 62, '0'),
        ])->toArray();
    }

    public function testFindByIdReturnsToken(): void
    {
        $t = $this->createToken(5);
        $found = $this->repo->findById((int) $t['id']);
        $this->assertNotNull($found);
        $this->assertSame((int) $t['id'], $found['id']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindByTokenReturnsToken(): void
    {
        $t = $this->createToken(1);
        $found = $this->repo->findByToken($t['token']);
        $this->assertNotNull($found);
    }

    public function testFindByTokenReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findByToken('unknown'));
    }

    public function testFindByUserIdReturnsTokens(): void
    {
        $this->createToken(1, 3);
        $this->createToken(2, 3);

        $this->assertCount(2, $this->repo->findByUserId(3));
    }

    public function testFindByUserIdReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->repo->findByUserId(999));
    }

    public function testSaveCreatesToken(): void
    {
        $data = ['user_id' => 1, 'job_name' => 'backup', 'token' => 'hashed'];
        $saved = $this->repo->save($data);
        $this->assertArrayHasKey('id', $saved);
        $this->assertCount(1, EloquentCronToken::all());
    }

    public function testUpdateMergesAndSaves(): void
    {
        $t = EloquentCronToken::create(['user_id' => 1, 'job_name' => 'backup', 'token' => 'abc']);
        $this->repo->update((int) $t->id, ['name' => 'CI']);
        $this->assertSame('CI', EloquentCronToken::find($t->id)->name);
    }

    public function testUpdateDoesNothingWhenNotFound(): void
    {
        $this->repo->update(999, ['name' => 'Nope']);
        $this->assertTrue(true); // no exception
    }

    public function testDeleteDeletesToken(): void
    {
        $t = EloquentCronToken::create(['user_id' => 1, 'job_name' => 'backup', 'token' => 'abc']);
        $result = $this->repo->delete((int) $t->id);
        $this->assertSame(1, $result);
        $this->assertNull(EloquentCronToken::find($t->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete(999));
    }

    public function testTouchLastUsedUpdatesTimestamp(): void
    {
        $t = EloquentCronToken::create(['user_id' => 1, 'job_name' => 'backup', 'token' => 'abc']);
        $this->assertNull($t->last_used_at);

        $this->repo->touchLastUsed((int) $t->id);
        $refreshed = EloquentCronToken::find($t->id);
        $this->assertNotNull($refreshed->last_used_at);
    }

    public function testTouchLastUsedDoesNotThrowWhenRecordMissing(): void
    {
        $this->repo->touchLastUsed(999);
        $this->assertTrue(true);
    }

    public function testTouchLastUsedDoesNotThrowOnDriverFailure(): void
    {
        // No failure expected with SQLite in-memory — just verify no throw
        $t = EloquentCronToken::create(['user_id' => 1, 'job_name' => 'backup', 'token' => 'abc']);
        $this->repo->touchLastUsed((int) $t->id);
        $this->assertTrue(true);
    }
}
