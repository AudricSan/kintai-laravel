<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftClaim\Services;

use kintai\Bundles\Installed\ShiftClaim\Services\WithdrawPendingClaimsOnClose;
use kintai\Contract\Event\OpenShiftClosed;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Shift retiré de la bourse par le Core (OpenShiftClosed, contrat 1.17) : seules les candidatures en attente passent à
 * « retirée ».
 */
final class WithdrawPendingClaimsOnCloseTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['shift_claims', 'shift-claim'];
    }

    public function testOnlyPendingClaimsAreWithdrawn(): void
    {
        $this->resetRows();
        $this->seedRows([
            ['id' => 1, 'shift_id' => 10, 'user_id' => 5, 'status' => 'pending'],
            ['id' => 2, 'shift_id' => 10, 'user_id' => 6, 'status' => 'approved'],
            ['id' => 3, 'shift_id' => 10, 'user_id' => 7, 'status' => 'pending'],
        ]);

        (new WithdrawPendingClaimsOnClose())(new OpenShiftClosed(['id' => 10, 'is_open' => 0], 1));

        $this->assertSame([1 => 'withdrawn', 2 => 'approved', 3 => 'withdrawn'], array_column($this->rowsInTable(), 'status', 'id'));
    }
}
