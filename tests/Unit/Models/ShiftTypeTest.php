<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use App\Models\ShiftType;
use App\Models\ShiftType as EloquentShiftType;
use App\Models\ShiftTypeStore;

final class ShiftTypeTest extends TestCase
{

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        foreach ([1, 2, 3, 5, 6] as $id) {
            \Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => $id, 'name' => 'Magasin ' . $id, 'code' => 'M' . $id]);
        }
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
        ShiftType::syncStores($t1, [1, 2]);
        ShiftType::syncStores($t2, [2]);

        $this->assertCount(1, ShiftType::findByStore(1));
        $this->assertCount(2, ShiftType::findByStore(2));
    }

    public function testFindByStoresReturnsUnionWithoutDuplicates(): void
    {
        $t1 = $this->type('MORNING');
        $t2 = $this->type('AFTERNOON');
        ShiftType::syncStores($t1, [1, 2]); // couvre les deux stores demandés
        ShiftType::syncStores($t2, [3]);

        $result = ShiftType::findByStores([1, 2]);

        $this->assertCount(1, $result); // t1 ne doit apparaître qu'une fois malgré les 2 matches
        $this->assertSame($t1, (int) $result[0]['id']);
    }

    public function testFindByStoresReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], ShiftType::findByStores([]));
    }

    // -------------------------------------------------------------------------
    // findActive()
    // -------------------------------------------------------------------------

    public function testFindActiveExcludesInactiveTypes(): void
    {
        $active   = $this->type('MORNING', true);
        $inactive = $this->type('NIGHT', false);
        ShiftType::syncStores($active, [1]);
        ShiftType::syncStores($inactive, [1]);

        $result = ShiftType::findActive(1);

        $this->assertCount(1, $result);
        $this->assertSame($active, (int) $result[0]['id']);
    }

    // -------------------------------------------------------------------------
    // getStoreIds() / syncStores()
    // -------------------------------------------------------------------------

    public function testSyncStoresReplacesExistingAssignments(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1, 2]);
        $this->assertSame([1, 2], ShiftType::getStoreIds($t));

        ShiftType::syncStores($t, [3]);
        $this->assertSame([3], ShiftType::getStoreIds($t));
    }

    public function testSyncStoresDeduplicatesInput(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1, 1, 2]);
        $this->assertSame([1, 2], ShiftType::getStoreIds($t));
    }

    public function testGetStoreIdsReturnsEmptyArrayWhenUnassigned(): void
    {
        $t = $this->type();
        $this->assertSame([], ShiftType::getStoreIds($t));
    }

    // -------------------------------------------------------------------------
    // isEnabledForStore()
    // -------------------------------------------------------------------------

    public function testIsEnabledForStore(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [5]);

        $this->assertTrue(ShiftType::isEnabledForStore($t, 5));
        $this->assertFalse(ShiftType::isEnabledForStore($t, 6));
    }

    // -------------------------------------------------------------------------
    // enableForStore() / disableForStore()
    // -------------------------------------------------------------------------

    public function testEnableForStoreAddsAssignmentWithoutTouchingOthers(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1]);

        ShiftType::enableForStore($t, 2);

        $this->assertSame([1, 2], ShiftType::getStoreIds($t));
    }

    public function testEnableForStoreIsIdempotent(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1]);

        ShiftType::enableForStore($t, 1);

        $this->assertSame([1], ShiftType::getStoreIds($t));
    }

    public function testDisableForStoreRemovesOnlyThatAssignment(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1, 2]);

        ShiftType::disableForStore($t, 1);

        $this->assertSame([2], ShiftType::getStoreIds($t));
    }

    public function testDisableForStoreIsIdempotent(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1]);

        ShiftType::disableForStore($t, 2);

        $this->assertSame([1], ShiftType::getStoreIds($t));
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteAlsoRemovesStoreAssignments(): void
    {
        $t = $this->type();
        ShiftType::syncStores($t, [1, 2]);

        $count = ShiftType::remove($t);

        $this->assertSame(1, $count);
        $this->assertNull(EloquentShiftType::find($t));
        $this->assertSame(0, ShiftTypeStore::where('shift_type_id', $t)->count());
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, ShiftType::remove(999));
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveDoesNotRequireStoreId(): void
    {
        $result = ShiftType::put([
            'name' => 'Matin', 'code' => 'MORNING',
            'start_time' => '08:00', 'end_time' => '16:00',
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayNotHasKey('store_id', $result);
    }
}
