<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\ImportAliasRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\UI\Controller\Web\Scheduling\AdminShiftImportController;
use kintai\UI\ViewRenderer;
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
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private ?AdminShiftImportController $controller = null;

    protected function setUp(): void
    {
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AdminShiftImportController
    {
        if ($this->controller === null) {
            $roleSync = new RoleAssignmentSyncService(
                $this->createStub(RoleRepositoryInterface::class),
                $this->createStub(RoleAssignmentRepositoryInterface::class),
            );
            $this->controller = new AdminShiftImportController(
                new ViewRenderer(sys_get_temp_dir()),
                $this->createStub(UserRepositoryInterface::class),
                $this->createStub(StoreRepositoryInterface::class),
                $this->createStub(ShiftRepositoryInterface::class),
                $this->createStub(ShiftTypeRepositoryInterface::class),
                $this->storeUsers,
                new AuditLogger(),
                $this->createStub(ImportAliasRepositoryInterface::class),
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
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->storeUsers->expects($this->atLeastOnce())->method('findByStore')->with(1)->willReturn([
            ['id' => 1, 'store_id' => 1, 'user_id' => 10],
            ['id' => 2, 'store_id' => 1, 'user_id' => 11],
        ]);

        $method = new \ReflectionMethod($this->controller(), 'storeMemberIds');
        $method->setAccessible(true);
        $ids = $method->invoke($this->controller(), 1);

        $this->assertSame([10, 11], $ids);
    }
}
