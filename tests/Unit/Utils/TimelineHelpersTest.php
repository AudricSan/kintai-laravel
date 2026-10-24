<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use kintai\UI\Utils\TimelineHelpers;

final class TimelineHelpersTest extends TestCase
{
    /**
     * Un type de shift peut désormais être activé sur plusieurs stores : la
     * répartition du temps travaillé par type ne doit compter que les types
     * activés pour le store du shift, pas ceux activés ailleurs même si
     * leurs horaires se chevauchent.
     */
    public function testAtPayBreakdownOnlyCountsTypesEnabledForShiftStore(): void
    {
        $typesMap = [
            1 => ['id' => 1, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'hourly_rate' => 1000],
            2 => ['id' => 2, 'name' => 'Matin autre store', 'start_time' => '08:00', 'end_time' => '16:00', 'hourly_rate' => 2000],
        ];
        $typeStoreIds = [
            1 => [1],    // type 1 activé pour le store 1 (celui du shift)
            2 => [2],    // type 2 activé uniquement pour le store 2
        ];

        $result = TimelineHelpers::atPayBreakdown(
            '09:00', '15:00', 0, false,
            uid: 42, storeId: 1, typesMap: $typesMap, typeStoreIds: $typeStoreIds,
            ratesMap: [], currency: 'JPY',
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame('Matin', $result['items'][0]['type_name']);
    }

    public function testAtPayBreakdownCountsTypeEnabledForMultipleStoresIncludingShiftStore(): void
    {
        $typesMap = [
            1 => ['id' => 1, 'name' => 'Journée', 'start_time' => '08:00', 'end_time' => '18:00', 'hourly_rate' => 1200],
        ];
        $typeStoreIds = [1 => [1, 2, 3]]; // partagé entre plusieurs stores, dont le store 1 du shift

        $result = TimelineHelpers::atPayBreakdown(
            '09:00', '15:00', 0, false,
            uid: 1, storeId: 1, typesMap: $typesMap, typeStoreIds: $typeStoreIds,
            ratesMap: [], currency: 'JPY',
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame('Journée', $result['items'][0]['type_name']);
    }

    public function testAtPayBreakdownReturnsNoItemsWhenNoTypeEnabledForShiftStore(): void
    {
        $typesMap = [
            1 => ['id' => 1, 'name' => 'Matin', 'start_time' => '08:00', 'end_time' => '16:00', 'hourly_rate' => 1000],
        ];
        $typeStoreIds = [1 => [2]]; // pas le store 1

        $result = TimelineHelpers::atPayBreakdown(
            '09:00', '15:00', 0, false,
            uid: 1, storeId: 1, typesMap: $typesMap, typeStoreIds: $typeStoreIds,
            ratesMap: [], currency: 'JPY',
        );

        $this->assertSame([], $result['items']);
        $this->assertSame(360, $result['net_minutes']); // repli : durée brute (6h) sans découpage par type
    }
}
