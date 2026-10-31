<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff\Services;

use kintai\Bundles\Installed\TimeOff\Services\PendingTimeoffWidget;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Widgets et carte « Congés en attente » des tableaux de bord (contrats 1.4 et 1.19) : mêmes demandes que celles que
 * le Core affichait.
 */
final class PendingTimeoffWidgetTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeoff_requests', 'timeoff'];
    }

    public function testManagerSeesPendingRequestsOfItsStores(): void
    {
        $this->resetRows();
        $this->seedRows([
            ['id' => 1, 'store_id' => 1, 'user_id' => 5, 'status' => 'pending'],
            ['id' => 2, 'store_id' => 2, 'user_id' => 5, 'status' => 'pending'],
            ['id' => 3, 'store_id' => 1, 'user_id' => 6, 'status' => 'approved'],
        ]);
        kintai_test_users(findAll: fn() => [['id' => 5, 'display_name' => 'Sato'], ['id' => 6, 'display_name' => 'tanaka@example.test', 'email' => 'tanaka@example.test']]);
        $data = (new PendingTimeoffWidget())->forManager([1]);

        $this->assertSame([1], array_column($data['pending_timeoff'], 'id'));
        $this->assertSame([5 => 'Sato', 6 => 'tanaka@example.test'], $data['users_map']);
    }

    public function testEmployeeSeesItsPendingRequests(): void
    {
        $this->resetRows();
        $this->seedRows([
            ['id' => 4, 'user_id' => 6, 'status' => 'pending'],
            ['id' => 5, 'user_id' => 6, 'status' => 'refused'],
        ]);

        $this->assertSame([4], array_column((new PendingTimeoffWidget())->forEmployee(6), 'id'));
    }
}
