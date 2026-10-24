<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseShiftTypeRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\ShiftType as EloquentShiftType;
use kintai\Domain\Eloquent\ShiftTypeStore;

final class DatabaseShiftTypeRepositoryTest extends TestCase
{
    private DatabaseShiftTypeRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('shift_types', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code');
            $table->string('start_time');
            $table->string('end_time');
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->string('color')->default('#3B82F6');
            $table->integer('sort_order')->default(0);
            $table->integer('is_active')->default(1);
        });
        $schema->create('shift_type_stores', function ($table) {
            $table->integer('shift_type_id');
            $table->integer('store_id');
            $table->primary(['shift_type_id', 'store_id']);
        });

        $this->repo = new DatabaseShiftTypeRepository();
    }

    private function type(string $code = 'MORNING', bool $active = true): int
    {
        $t = EloquentShiftType::create([
            'name' => $code, 'code' => $code,
            'start_time' => '08:00', 'end_time' => '16:00',
            'is_active' => $active ? 1 : 0,
        ]);
        return (int) $t->id;
    }

    // -------------------------------------------------------------------------
    // findByStore() / findByStores()
    // -------------------------------------------------------------------------

    public function testFindByStoreReturnsOnlyTypesEnabledForThatStore(): void
    {
        $t1 = $this->type('MORNING');
        $t2 = $this->type('AFTERNOON');
        $this->repo->syncStores($t1, [1, 2]);
        $this->repo->syncStores($t2, [2]);

        $this->assertCount(1, $this->repo->findByStore(1));
        $this->assertCount(2, $this->repo->findByStore(2));
    }

    public function testFindByStoresReturnsUnionWithoutDuplicates(): void
    {
        $t1 = $this->type('MORNING');
        $t2 = $this->type('AFTERNOON');
        $this->repo->syncStores($t1, [1, 2]); // couvre les deux stores demandés
        $this->repo->syncStores($t2, [3]);

        $result = $this->repo->findByStores([1, 2]);

        $this->assertCount(1, $result); // t1 ne doit apparaître qu'une fois malgré les 2 matches
        $this->assertSame($t1, (int) $result[0]['id']);
    }

    public function testFindByStoresReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->repo->findByStores([]));
    }

    // -------------------------------------------------------------------------
    // findActive()
    // -------------------------------------------------------------------------

    public function testFindActiveExcludesInactiveTypes(): void
    {
        $active   = $this->type('MORNING', true);
        $inactive = $this->type('NIGHT', false);
        $this->repo->syncStores($active, [1]);
        $this->repo->syncStores($inactive, [1]);

        $result = $this->repo->findActive(1);

        $this->assertCount(1, $result);
        $this->assertSame($active, (int) $result[0]['id']);
    }

    // -------------------------------------------------------------------------
    // getStoreIds() / syncStores()
    // -------------------------------------------------------------------------

    public function testSyncStoresReplacesExistingAssignments(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1, 2]);
        $this->assertSame([1, 2], $this->repo->getStoreIds($t));

        $this->repo->syncStores($t, [3]);
        $this->assertSame([3], $this->repo->getStoreIds($t));
    }

    public function testSyncStoresDeduplicatesInput(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1, 1, 2]);
        $this->assertSame([1, 2], $this->repo->getStoreIds($t));
    }

    public function testGetStoreIdsReturnsEmptyArrayWhenUnassigned(): void
    {
        $t = $this->type();
        $this->assertSame([], $this->repo->getStoreIds($t));
    }

    // -------------------------------------------------------------------------
    // isEnabledForStore()
    // -------------------------------------------------------------------------

    public function testIsEnabledForStore(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [5]);

        $this->assertTrue($this->repo->isEnabledForStore($t, 5));
        $this->assertFalse($this->repo->isEnabledForStore($t, 6));
    }

    // -------------------------------------------------------------------------
    // enableForStore() / disableForStore()
    // -------------------------------------------------------------------------

    public function testEnableForStoreAddsAssignmentWithoutTouchingOthers(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1]);

        $this->repo->enableForStore($t, 2);

        $this->assertSame([1, 2], $this->repo->getStoreIds($t));
    }

    public function testEnableForStoreIsIdempotent(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1]);

        $this->repo->enableForStore($t, 1);

        $this->assertSame([1], $this->repo->getStoreIds($t));
    }

    public function testDisableForStoreRemovesOnlyThatAssignment(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1, 2]);

        $this->repo->disableForStore($t, 1);

        $this->assertSame([2], $this->repo->getStoreIds($t));
    }

    public function testDisableForStoreIsIdempotent(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1]);

        $this->repo->disableForStore($t, 2);

        $this->assertSame([1], $this->repo->getStoreIds($t));
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteAlsoRemovesStoreAssignments(): void
    {
        $t = $this->type();
        $this->repo->syncStores($t, [1, 2]);

        $count = $this->repo->delete($t);

        $this->assertSame(1, $count);
        $this->assertNull(EloquentShiftType::find($t));
        $this->assertSame(0, ShiftTypeStore::where('shift_type_id', $t)->count());
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete(999));
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveDoesNotRequireStoreId(): void
    {
        $result = $this->repo->save([
            'name' => 'Matin', 'code' => 'MORNING',
            'start_time' => '08:00', 'end_time' => '16:00',
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayNotHasKey('store_id', $result);
    }
}
