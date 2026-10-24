<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
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
use kintai\Core\Services\LicenseClientService;
use kintai\Core\Services\Log;
use kintai\Core\Services\PlanLimitService;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\UI\Controller\Web\Staff\AdminUserController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Régression (audit du 03/10/2026) : les actions /admin/users/{id}/... ne vérifiaient ni le
 * magasin de l'employé ciblé ni son statut Owner — PermissionMiddleware ne contrôle que
 * « permission accordée quelque part ». Un gérant du magasin 5 pouvait donc réinitialiser le
 * mot de passe de l'Owner ou modifier/supprimer un employé du magasin 6.
 */
final class AdminUserControllerScopeTest extends TestCase
{
    private const OWNER   = 1;
    private const MANAGER = 2;
    private const STORE_A = 5;
    private const STORE_B = 6;

    private AdminUserController $controller;
    private UserRepositoryInterface&MockObject $users;
    private StoreUserRepositoryInterface&MockObject $storeUsers;

    protected function setUp(): void
    {
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturnCallback(fn(int $uid): array => match ($uid) {
            self::OWNER   => [['id' => 1, 'user_id' => self::OWNER, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]],
            self::MANAGER => [['id' => 2, 'user_id' => self::MANAGER, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::STORE_A]],
            default       => [],
        });
        $assignments->method('findByScope')->willReturn([
            ['id' => 1, 'user_id' => self::OWNER, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturnCallback(fn(int $id): array => $id === 10
            ? ['id' => 10, 'is_system' => 1]
            : ['id' => 20, 'is_system' => 0]);
        $roles->method('getPermissions')->willReturn(['employees.view', 'employees.update', 'employees.delete']);
        $roles->method('getGlobalPermissionKeys')->willReturn([]);

        $this->users      = $this->createMock(UserRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $stores           = $this->createStub(StoreRepositoryInterface::class);

        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->createStub(LogRepositoryInterface::class));
        Log::setContainer($container);

        $this->controller = new AdminUserController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $stores,
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->storeUsers,
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            new RoleAssignmentSyncService($roles, $assignments),
            new PermissionService($assignments, $roles),
            new PlanLimitService(
                $stores,
                $this->users,
                new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
            ),
            new OwnerGuard(new PermissionService($assignments, $roles), $this->users),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function asManager(int $targetId, array $post = []): Request
    {
        $_POST = $post;
        $req = new Request();
        $req->setRouteParams(['id' => (string) $targetId]);
        $req->setAttribute('auth_user', ['id' => self::MANAGER, 'is_admin' => false]);
        $req->setAttribute('managed_store_ids', [self::STORE_A]);
        return $req;
    }

    private function target(int $id, int $storeId): void
    {
        $this->users->method('findById')->with($id)->willReturn(['id' => $id, 'email' => 'x@example.com']);
        $this->storeUsers->method('findByUser')->with($id)->willReturn([['store_id' => $storeId]]);
    }

    public function testManagerCannotResetOwnerPassword(): void
    {
        // Même membre du magasin du gérant, un Owner reste intouchable pour un non-Owner.
        $this->target(self::OWNER, self::STORE_A);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ForbiddenException::class);
        $this->controller->resetPassword($this->asManager(self::OWNER));
    }

    public function testManagerCannotResetPasswordOfAnotherStoreEmployee(): void
    {
        $this->target(30, self::STORE_B);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ForbiddenException::class);
        $this->controller->resetPassword($this->asManager(30));
    }

    public function testManagerCannotUpdateAnotherStoreEmployee(): void
    {
        $this->target(30, self::STORE_B);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ForbiddenException::class);
        $this->controller->updateUser($this->asManager(30, ['email' => 'x@example.com']));
    }

    public function testManagerCannotDeleteAnotherStoreEmployee(): void
    {
        $this->users->expects($this->never())->method('delete');
        $this->storeUsers->method('findByUser')->with(30)->willReturn([['store_id' => self::STORE_B]]);

        $this->expectException(ForbiddenException::class);
        $this->controller->deleteUser($this->asManager(30));
    }

    public function testManagerCannotViewAnotherStoreEmployeeProfile(): void
    {
        $this->target(30, self::STORE_B);

        $this->expectException(ForbiddenException::class);
        $this->controller->editUser($this->asManager(30));
    }

    public function testManagerCanResetPasswordOfOwnStoreEmployee(): void
    {
        $this->target(31, self::STORE_A);
        $this->users->expects($this->once())->method('save');

        $response = $this->controller->resetPassword($this->asManager(31));

        $this->assertSame(302, $response->status());
    }

    // ------------------------------------------- actions sur soi-même (audit M13)

    public function testManagerCannotDeleteTheirOwnAccountFromTheAdmin(): void
    {
        $this->target(self::MANAGER, self::STORE_A);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->controller->deleteUser($this->asManager(self::MANAGER));
    }

    public function testManagerCannotDeactivateThemselvesFromTheAdmin(): void
    {
        $this->users->method('findById')->with(self::MANAGER)->willReturn(['id' => self::MANAGER, 'email' => 'm@example.com', 'is_active' => 1]);
        $this->storeUsers->method('findByUser')->with(self::MANAGER)->willReturn([['store_id' => self::STORE_A]]);
        $this->users->expects($this->never())->method('save');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        // Sélecteur « actif » sur « inactif » (un champ absent, lui, laisse l'état inchangé : audit M12).
        $this->controller->updateUser($this->asManager(self::MANAGER, ['email' => 'm@example.com', 'first_name' => 'M', 'last_name' => 'G', 'is_active' => '0']));
    }
}
