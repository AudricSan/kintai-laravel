<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\PermissionService;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use App\Services\AuditLogger;
use App\Services\RoleAssignmentSyncService;
use App\Http\Controllers\Scheduling\AdminShiftImportController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * L'import Excel matche les noms de la feuille aux employés existants. Deux
 * employés de magasins différents peuvent partager le même nom de famille ;
 * la correspondance automatique ne doit porter que sur les membres du magasin
 * courant, sous peine d'assigner un shift au mauvais utilisateur.
 */
final class AdminShiftImportControllerTest extends TestCase
{
    private ?AdminShiftImportController $controller = null;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminShiftImportController
    {
        if ($this->controller === null) {
            $roleSync = new RoleAssignmentSyncService(
            );
            $this->controller = new AdminShiftImportController(
                kintai_test_views(sys_get_temp_dir()),
                $this->createStub(UserRepositoryInterface::class),
                $this->createStub(StoreRepositoryInterface::class),
                new AuditLogger(),
                $roleSync,
            );
        }

        return $this->controller;
    }

    public function testResolveEntryUserIdsIgnoresHomonymFromAnotherStore(): void
    {
        $entries = [
            ['staff_name' => 'Dupont'],
        ];

        // Seul l'utilisateur #10 (Dupont Jean) est membre du magasin courant ;
        // #20 (Dupont Marie) est un homonyme d'un autre magasin et ne doit
        // jamais être proposé automatiquement.
        $storeMembers = [
            ['id' => 10, 'last_name' => 'Dupont', 'first_name' => 'Jean', 'display_name' => 'Dupont'],
        ];

        $method = new \ReflectionMethod($this->controller(), 'resolveEntryUserIds');
        $method->setAccessible(true);
        $resolved = $method->invoke($this->controller(), $entries, $storeMembers, []);

        $this->assertSame(10, $resolved[0]['user_id']);
    }

    public function testResolveEntryUserIdsLeavesUnassignedWhenNameOnlyMatchesAnotherStore(): void
    {
        $entries = [
            ['staff_name' => 'Martin'],
        ];

        // Aucun "Martin" dans ce magasin : même si un "Martin" existe ailleurs
        // dans l'application, il ne doit pas être auto-assigné ici.
        $storeMembers = [
            ['id' => 10, 'last_name' => 'Dupont', 'first_name' => 'Jean', 'display_name' => 'Dupont'],
        ];

        $method = new \ReflectionMethod($this->controller(), 'resolveEntryUserIds');
        $method->setAccessible(true);
        $resolved = $method->invoke($this->controller(), $entries, $storeMembers, []);

        $this->assertSame(0, $resolved[0]['user_id']);
    }

    public function testResolveEntryUserIdsFallsBackToStoreScopedAlias(): void
    {
        $entries = [
            ['staff_name' => 'M. Dupont (surnom)'],
        ];

        $resolved = (new \ReflectionMethod($this->controller(), 'resolveEntryUserIds'))
            ->invoke($this->controller(), $entries, [], ['m. dupont (surnom)' => 10]);

        $this->assertSame(10, $resolved[0]['user_id']);
    }

    public function testStoreMemberIdsReturnsUserIdsOfStoreMembership(): void
    {
        kintai_test_store_users(findByStore: fn(int $id) => $id === 1 ? [
            ['id' => 1, 'store_id' => 1, 'user_id' => 10],
            ['id' => 2, 'store_id' => 1, 'user_id' => 11],
        ] : []);
        $method = new \ReflectionMethod($this->controller(), 'storeMemberIds');
        $method->setAccessible(true);
        $ids = $method->invoke($this->controller(), 1);

        $this->assertSame([10, 11], $ids);
    }
}
