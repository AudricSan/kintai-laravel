<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use App\Models\StoreUser;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\StoreUser as EloquentStoreUser;

final class DatabaseStoreUserRepositoryTest extends TestCase
{

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('store_user', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('user_id');
            $table->string('staff_code')->nullable();
        });
    }

    private function membership(int $id, int $storeId = 1, int $userId = 10, ?string $staffCode = null): array
    {
        return ['id' => $id, 'store_id' => $storeId, 'user_id' => $userId, 'staff_code' => $staffCode];
    }

    // -------------------------------------------------------------------------
    // findById()
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsMembership(): void
    {
        $m = EloquentStoreUser::create(['store_id' => 1, 'user_id' => 10, 'staff_code' => 'STF001']);
        $found = StoreUser::findById($m->id);
        $this->assertNotNull($found);
        $this->assertEquals('STF001', $found['staff_code']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull(StoreUser::findById(999));
    }

    // -------------------------------------------------------------------------
    // findByStore()
    // -------------------------------------------------------------------------

    public function testFindByStoreReturnsAllMembersForStore(): void
    {
        EloquentStoreUser::create(['store_id' => 2, 'user_id' => 10]);
        EloquentStoreUser::create(['store_id' => 2, 'user_id' => 11]);
        EloquentStoreUser::create(['store_id' => 3, 'user_id' => 12]);

        $this->assertCount(2, StoreUser::findByStore(2));
    }

    // -------------------------------------------------------------------------
    // findByUser()
    // -------------------------------------------------------------------------

    public function testFindByUserReturnsAllStoresForUser(): void
    {
        EloquentStoreUser::create(['store_id' => 1, 'user_id' => 5]);
        EloquentStoreUser::create(['store_id' => 3, 'user_id' => 5]);

        $result = StoreUser::findByUser(5);
        $this->assertCount(2, $result);
    }

    public function testFindByUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], StoreUser::findByUser(99));
    }

    // -------------------------------------------------------------------------
    // findMembership()
    // -------------------------------------------------------------------------

    public function testFindMembershipReturnsMembership(): void
    {
        EloquentStoreUser::create(['store_id' => 3, 'user_id' => 7, 'staff_code' => 'STF002']);
        $result = StoreUser::findMembership(3, 7);
        $this->assertNotNull($result);
        $this->assertSame('STF002', $result['staff_code']);
    }

    public function testFindMembershipReturnsNullWhenNotMember(): void
    {
        $this->assertNull(StoreUser::findMembership(1, 999));
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveCreatesMembership(): void
    {
        $data = ['store_id' => 1, 'user_id' => 10, 'staff_code' => 'STF003'];
        $result = StoreUser::put($data);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('STF003', $result['staff_code']);
    }

    public function testSaveUpdatesMembership(): void
    {
        $m = EloquentStoreUser::create(['store_id' => 1, 'user_id' => 10, 'staff_code' => 'STF003']);
        $result = StoreUser::put(['id' => $m->id, 'staff_code' => 'STF004']);
        $this->assertEquals('STF004', $result['staff_code']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteDeletesMembership(): void
    {
        $m = EloquentStoreUser::create(['store_id' => 1, 'user_id' => 1]);
        $count = StoreUser::remove($m->id);
        $this->assertEquals(1, $count);
        $this->assertNull(EloquentStoreUser::find($m->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, StoreUser::remove(999));
    }
}
