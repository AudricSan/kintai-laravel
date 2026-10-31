<?php

declare(strict_types=1);

namespace Tests\Bundles\ShiftSwap\Services;

use kintai\Bundles\Installed\ShiftSwap\Services\PendingSwapsWidget;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Widgets et carte « Échanges » des tableaux de bord (contrats 1.4 et 1.19) : mêmes demandes que celles que le Core
 * affichait.
 */
final class PendingSwapsWidgetTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['shift_swap_requests', 'shift-swap'];
    }

    private function widget(): PendingSwapsWidget
    {
        $this->resetRows();
        $this->seedRows([
            ['id' => 1, 'store_id' => 1, 'status' => 'pending', 'requester_id' => 5, 'target_user_id' => 6, 'accepted_at' => '2026-10-01 09:00:00'],
            ['id' => 2, 'store_id' => 2, 'status' => 'pending', 'requester_id' => 5, 'target_user_id' => 6, 'accepted_at' => '2026-10-01 09:00:00'],
            ['id' => 3, 'store_id' => 1, 'status' => 'accepted', 'requester_id' => 5, 'target_user_id' => 6, 'accepted_at' => '2026-10-01 09:00:00'],
        ]);
        $this->seedRows([
            ['id' => 4, 'requester_id' => 5, 'target_user_id' => 6, 'status' => 'pending', 'accepted_at' => null],
            ['id' => 5, 'requester_id' => 5, 'target_user_id' => 6, 'status' => 'pending', 'accepted_at' => '2026-10-01 10:00:00'],
            ['id' => 6, 'requester_id' => 5, 'target_user_id' => 6, 'status' => 'refused', 'accepted_at' => null],
        ]);
        kintai_test_users(findAll: fn() => [['id' => 5, 'display_name' => 'Sato'], ['id' => 6, 'display_name' => 'tanaka@example.test', 'email' => 'tanaka@example.test']]);
        return new PendingSwapsWidget();
    }

    public function testManagerSeesPendingSwapsOfItsStores(): void
    {
        $data = $this->widget()->forManager([1]);

        $this->assertSame([1], array_column($data['pending_swaps'], 'id'));
        $this->assertSame([5 => 'Sato', 6 => 'tanaka@example.test'], $data['users_map']);
    }

    public function testEmployeeSeesTheRequestsWaitingForItsAnswer(): void
    {
        $this->assertSame([4], array_column($this->widget()->forEmployee(6), 'id'));
    }
}
