<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Exceptions\ForbiddenException;
use App\Models\Availability;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvailabilityGuard;
use App\Services\NotificationService;
use App\Services\ShiftServiceInterface;
use App\Http\Controllers\Scheduling\AdminShiftController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * POST /admin/shifts/conflicts/resolve-newer, mode unitaire (audit n°8, I1) : la route ne vérifiait pas que les deux
 * shifts étaient en conflit, donc supprimait n'importe quel shift de ses magasins avec la seule permission
 * shifts.update ; et un shift déjà supprimé (double clic) faisait une erreur 500.
 */
final class AdminShiftResolveConflictTest extends TestCase
{
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
        kintai_test_database();
        kintai_test_migrate();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        $this->notifs = $this->createMock(NotificationService::class);
        kintai_test_shifts(array_values($this->rows));

        $this->controller = new AdminShiftController(
            kintai_test_views(sys_get_temp_dir()),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            new AuditLogger(),
            $this->notifs,
            $this->createStub(ShiftServiceInterface::class),
            new PermissionService(),
            new AvailabilityGuard(),
        );
    }

    private function resolve(int $keep, int $delete): \Symfony\Component\HttpFoundation\Response
    {
        $_POST = ['id_keep' => (string) $keep, 'id_delete' => (string) $delete];
        $req = Request::capture();
        $_POST = [];
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->attributes->set('managed_store_ids', null);
        return $this->controller->resolveNewerConflict($req);
    }

    public function testOverlappingShiftsOfTheSameEmployeeAreResolved(): void
    {
        // L'employé est prévenu, comme pour une suppression directe.
        $this->notifs->expects($this->once())->method('notify')->with(3, 'shift_deleted', 'notif_shift_deleted_body', $this->anything(), $this->greaterThan(20000101));
        $this->assertSame(302, $this->resolve(2, 1)->getStatusCode());
        $this->assertNull(Shift::findById(1), 'shift supprimé');
    }

    public function testOvernightShiftOverlapIsAConflict(): void
    {
        $this->notifs->expects($this->once())->method('notify');
        $this->resolve(6, 5);
        $this->assertNull(Shift::findById(5), 'shift supprimé');
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
        $this->notifs->expects($this->never())->method('notify');
        $this->expectException(ForbiddenException::class);
        $this->resolve($keep, $delete);
    }

    public function testAlreadyDeletedShiftRedirectsWithoutError(): void
    {
        $this->notifs->expects($this->never())->method('notify');
        $this->assertSame(302, $this->resolve(2, 99)->getStatusCode());
    }

    public function testBulkResolutionNotifiesEachEmployeeWhoseShiftIsDeleted(): void
    {
        $this->notifs->expects($this->exactly(2))->method('notify')->with(3, 'shift_deleted');

        $_POST = ['bulk' => '1', 'month' => '2026-10'];
        $req = Request::capture();
        $_POST = [];
        $req->attributes->set('auth_user', ['id' => 1]);
        $req->attributes->set('managed_store_ids', null);
        $this->controller->resolveNewerConflict($req);
    }
}
