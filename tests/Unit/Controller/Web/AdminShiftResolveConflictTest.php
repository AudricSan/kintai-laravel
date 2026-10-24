<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvailabilityGuard;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\ShiftServiceInterface;
use kintai\UI\Controller\Web\Scheduling\AdminShiftController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * POST /admin/shifts/conflicts/resolve-newer, mode unitaire (audit n°8, I1) : la route ne vérifiait pas que les deux
 * shifts étaient en conflit, donc supprimait n'importe quel shift de ses magasins avec la seule permission
 * shifts.update ; et un shift déjà supprimé (double clic) faisait une erreur 500.
 */
final class AdminShiftResolveConflictTest extends TestCase
{
    private ShiftRepositoryInterface&MockObject $shifts;
    private NotificationService&MockObject $notifs;
    private AdminShiftController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [
        1 => ['id' => 1, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '17:00'],
        2 => ['id' => 2, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-10-20', 'start_time' => '13:00', 'end_time' => '21:00'],
        3 => ['id' => 3, 'store_id' => 1, 'user_id' => 4, 'shift_date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '17:00'],
        4 => ['id' => 4, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-10-21', 'start_time' => '09:00', 'end_time' => '17:00'],
        5 => ['id' => 5, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-10-19', 'start_time' => '22:00', 'end_time' => '06:00', 'cross_midnight' => 1],
        6 => ['id' => 6, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-10-20', 'start_time' => '05:00', 'end_time' => '08:00'],
    ];

    protected function setUp(): void
    {
        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);
        $this->shifts->method('findById')->willReturnCallback(fn(int $id) => $this->rows[$id] ?? null);

        $this->controller = new AdminShiftController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->shifts,
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            $this->notifs,
            $this->createStub(ShiftServiceInterface::class),
            new PermissionService($this->createStub(RoleAssignmentRepositoryInterface::class), $this->createStub(RoleRepositoryInterface::class)),
            new AvailabilityGuard($this->createStub(AvailabilityRepositoryInterface::class)),
        );
    }

    private function resolve(int $keep, int $delete): \kintai\Core\Response
    {
        $_POST = ['id_keep' => (string) $keep, 'id_delete' => (string) $delete];
        $req = new Request();
        $_POST = [];
        $req->setAttribute('auth_user', ['id' => 1]);
        $req->setAttribute('managed_store_ids', null);
        return $this->controller->resolveNewerConflict($req);
    }

    public function testOverlappingShiftsOfTheSameEmployeeAreResolved(): void
    {
        $this->shifts->expects($this->once())->method('delete')->with(1);
        // L'employé est prévenu, comme pour une suppression directe.
        $this->notifs->expects($this->once())->method('notify')->with(3, 'shift_deleted', 'notif_shift_deleted_body', $this->anything(), $this->greaterThan(20000101));
        $this->assertSame(302, $this->resolve(2, 1)->status());
    }

    public function testOvernightShiftOverlapIsAConflict(): void
    {
        $this->shifts->expects($this->once())->method('delete')->with(5);
        $this->notifs->expects($this->once())->method('notify');
        $this->resolve(6, 5);
    }

    /** @return array<string, array{int, int}> */
    public static function notInConflict(): array
    {
        return [
            'autre employé'      => [1, 3],
            'autre jour'         => [1, 4],
            'même shift'         => [1, 1],
            'nuit sans chevauchement' => [1, 5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notInConflict')]
    public function testShiftsNotInConflictAreNeverDeleted(int $keep, int $delete): void
    {
        $this->shifts->expects($this->never())->method('delete');
        $this->notifs->expects($this->never())->method('notify');
        $this->expectException(ForbiddenException::class);
        $this->resolve($keep, $delete);
    }

    public function testAlreadyDeletedShiftRedirectsWithoutError(): void
    {
        $this->shifts->expects($this->never())->method('delete');
        $this->notifs->expects($this->never())->method('notify');
        $this->assertSame(302, $this->resolve(2, 99)->status());
    }

    public function testBulkResolutionNotifiesEachEmployeeWhoseShiftIsDeleted(): void
    {
        $this->shifts->method('findBetween')->willReturn(array_values($this->rows));
        $this->shifts->expects($this->exactly(2))->method('delete');
        $this->notifs->expects($this->exactly(2))->method('notify')->with(3, 'shift_deleted');

        $_POST = ['bulk' => '1', 'month' => '2026-10'];
        $req = new Request();
        $_POST = [];
        $req->setAttribute('auth_user', ['id' => 1]);
        $req->setAttribute('managed_store_ids', null);
        $this->controller->resolveNewerConflict($req);
    }
}
