<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\ActivityEntry;

final class DatabaseLogRepositoryTest extends TestCase
{

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
    }

    public function testFindAllPaginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => "msg{$i}", 'created_at' => date('Y-m-d H:i:s', strtotime("2025-01-{$i} 00:00:00"))]);
        }

        $page1 = ActivityEntry::findAll(1, 10);
        $this->assertCount(10, $page1);

        $page2 = ActivityEntry::findAll(2, 10);
        $this->assertCount(10, $page2);

        $page3 = ActivityEntry::findAll(3, 10);
        $this->assertCount(5, $page3);

        $this->assertSame('msg25', $page1[0]['message']);
        $this->assertSame('msg16', $page1[9]['message']);
    }

    public function testCountAll(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => "msg{$i}", 'created_at' => date('Y-m-d H:i:s')]);
        }
        $this->assertSame(7, ActivityEntry::countAll());
    }

    public function testCountAllWithFilter(): void
    {
        ActivityEntry::create(['level' => 'error', 'channel' => 'test', 'message' => 'err', 'created_at' => date('Y-m-d H:i:s')]);
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'ok', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(1, ActivityEntry::countAll(['level' => 'error']));
    }

    public function testFindById(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'hello']);
        $id = ActivityEntry::first()->id;
        $found = ActivityEntry::findById($id);
        $this->assertNotNull($found);
        $this->assertSame('hello', $found['message']);

        $this->assertNull(ActivityEntry::findById(99999));
    }

    public function testFindChannels(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'alpha', 'message' => 'a']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'beta', 'message' => 'b']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'alpha', 'message' => 'a2']);
        $channels = ActivityEntry::findChannels();
        sort($channels);
        $this->assertSame(['alpha', 'beta'], $channels);
    }

    public function testFindActions(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'a', 'action' => 'shift.created']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'b', 'action' => 'user.deleted']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'business', 'message' => 'c']);
        $actions = ActivityEntry::findActions();
        sort($actions);
        $this->assertSame(['shift.created', 'user.deleted'], $actions);
    }

    public function testFindResourceTypes(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'a', 'resource_type' => 'shift']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'b', 'resource_type' => 'user']);
        $types = ActivityEntry::findResourceTypes();
        sort($types);
        $this->assertSame(['shift', 'user'], $types);
    }

    public function testFindAllWithActionFilter(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'a', 'action' => 'shift.created', 'created_at' => '2025-01-02 00:00:00']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'b', 'action' => 'user.deleted', 'created_at' => '2025-01-01 00:00:00']);
        $rows = ActivityEntry::findAll(1, 10, ['action' => 'shift.created']);
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['message']);
    }

    public function testFindAllWithTextQuery(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'hello world', 'created_at' => '2025-01-01 00:00:00']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'goodbye', 'created_at' => '2025-01-02 00:00:00']);
        $rows = ActivityEntry::findAll(1, 10, ['query' => 'hello']);
        $this->assertCount(1, $rows);
    }

    public function testFindAllWithStoreIdsFilterAppliesWhereIn(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'store1', 'store_id' => 1, 'created_at' => '2025-01-01 00:00:00']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'store2', 'store_id' => 2, 'created_at' => '2025-01-02 00:00:00']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'store3', 'store_id' => 3, 'created_at' => '2025-01-03 00:00:00']);

        $rows = ActivityEntry::findAll(1, 10, ['store_ids' => [1, 3]]);

        $this->assertCount(2, $rows);
        $messages = array_column($rows, 'message');
        $this->assertContains('store1', $messages);
        $this->assertContains('store3', $messages);
        $this->assertNotContains('store2', $messages);
    }

    public function testFindAllWithEmptyStoreIdsFilterReturnsNoRows(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'store1', 'store_id' => 1, 'created_at' => '2025-01-01 00:00:00']);

        // Un manager scopé mais n'ayant la permission sur aucun store : store_ids = [].
        $rows = ActivityEntry::findAll(1, 10, ['store_ids' => []]);

        $this->assertCount(0, $rows);
    }

    public function testCountAllWithStoreIdsFilter(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'store1', 'store_id' => 1, 'created_at' => '2025-01-01 00:00:00']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'audit', 'message' => 'store2', 'store_id' => 2, 'created_at' => '2025-01-02 00:00:00']);

        $this->assertSame(1, ActivityEntry::countAll(['store_ids' => [1]]));
    }

    public function testPurgeOlderThanDeletesOnlyOlderEntries(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'old', 'created_at' => '2025-01-01 00:00:00']);
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'recent', 'created_at' => '2026-06-01 00:00:00']);

        $deleted = ActivityEntry::purgeOlderThan('2026-01-01 00:00:00');

        $this->assertSame(1, $deleted);
        $this->assertSame(1, ActivityEntry::count());
        $this->assertSame('recent', ActivityEntry::first()->message);
    }

    public function testPurgeOlderThanReturnsZeroWhenNothingMatches(): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'test', 'message' => 'recent', 'created_at' => '2026-06-01 00:00:00']);

        $this->assertSame(0, ActivityEntry::purgeOlderThan('2020-01-01 00:00:00'));
        $this->assertSame(1, ActivityEntry::count());
    }
}
