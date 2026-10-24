<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** Référence d'un jour de planning pour les notifications de shift supprimé (le shift n'existe plus). */
final class ShiftDayReferenceTest extends TestCase
{
    public function testDateBecomesAnInteger(): void
    {
        $this->assertSame(20260802, shift_day_reference('2026-08-02'));
    }

    public function testEmptyDateGivesZero(): void
    {
        $this->assertSame(0, shift_day_reference(''));
    }
}
