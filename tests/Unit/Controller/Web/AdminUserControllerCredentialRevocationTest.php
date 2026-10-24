<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Quand un admin change le mot de passe d'un utilisateur, le réinitialise, le désactive ou le
 * supprime, ses cookies « rester connecté » et ses jetons d'API doivent être révoqués (ses
 * sessions ouvertes tombent d'elles-mêmes via AuthService).
 */
final class AdminUserControllerCredentialRevocationTest extends TestCase
{
    private const TARGET_ID = 7;

    private AdminUserController $controller;
    private UserRepositoryInterface&Stub $users;
    private RememberTokenRepositoryInterface&MockObject $remember;
    private ApiTokenRepositoryInterface&MockObject $api;

    protected function setUp(): void
    {
        $this->users = $this->createStub(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn([
            'id'                  => self::TARGET_ID,
            'email'               => 'target@example.test',
            'first_name'          => 'Jean',
            'last_name'           => 'Dupont',
            'furigana_first_name' => 'ジャン',
            'furigana_last_name'  => 'デュポン',
            'display_name'        => 'Jean Dupont',
            'employee_code'       => null,
            'is_active'           => 1,
            'deleted_at'          => null,
            'password_hash'       => 'not-a-real-hash',
        ]);

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);

        // Demandeur : rôle système global (la garde d'accès à l'employé ciblé doit le laisser passer).
        $roleAssignments->method('findByUser')->willReturn([['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]]);
        $roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);

        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->createStub(LogRepositoryInterface::class));
        Log::setContainer($container);

        $this->remember = $this->createMock(RememberTokenRepositoryInterface::class);
        $this->api      = $this->createMock(ApiTokenRepositoryInterface::class);

        $this->controller = new AdminUserController(
            new ViewRenderer(sys_get_temp_dir()),
            $this->users,
            $stores,
            $this->createStub(ShiftRepositoryInterface::class),
            $this->createStub(ShiftTypeRepositoryInterface::class),
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(UserShiftTypeRateRepositoryInterface::class),
            new AuditLogger(),
            new RoleAssignmentSyncService($roles, $roleAssignments),
            new PermissionService($roleAssignments, $roles),
            new PlanLimitService(
                $stores,
                $this->users,
                new LicenseClientService($this->createStub(AppSettingsRepositoryInterface::class), ['base_url' => '', 'api_key' => '']),
            ),
            new OwnerGuard(new PermissionService($roleAssignments, $roles), $this->users),
            new CredentialRevoker($this->remember, $this->api),
            new UserAnonymizer($this->users, $roleAssignments, new CredentialRevoker($this->remember, $this->api), sys_get_temp_dir()),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function request(array $post): Request
    {
        $_POST = $post;
        $req = new Request();
        $req->setRouteParams(['id' => (string) self::TARGET_ID]);
        $req->setAttribute('auth_user', ['id' => 1, 'is_admin' => false]);
        return $req;
    }

    private function expectRevocation(): void
    {
        $this->remember->expects($this->once())->method('deleteByUserId')->with(self::TARGET_ID);
        $this->api->expects($this->once())->method('deleteByUserId')->with(self::TARGET_ID)->willReturn(0);
    }

    private function expectNoRevocation(): void
    {
        $this->remember->expects($this->never())->method('deleteByUserId');
        $this->api->expects($this->never())->method('deleteByUserId');
    }

    public function testResetPasswordRevokesCredentials(): void
    {
        $this->expectRevocation();

        $this->controller->resetPassword($this->request([]));
    }

    public function testDeleteUserRevokesCredentials(): void
    {
        $this->expectRevocation();

        $this->controller->deleteUser($this->request([]));
    }

    public function testUpdateUserWithANewPasswordRevokesCredentials(): void
    {
        $this->expectRevocation();

        $this->controller->updateUser($this->request(['is_active' => '1', 'password' => bin2hex(random_bytes(8))]));
    }

    public function testDeactivatingAnActiveUserRevokesCredentials(): void
    {
        $this->expectRevocation();

        $this->controller->updateUser($this->request(['is_active' => '0']));
    }

    public function testUpdateUserWithoutPasswordOrDeactivationRevokesNothing(): void
    {
        $this->expectNoRevocation();

        $this->controller->updateUser($this->request(['is_active' => '1', 'phone' => '090-1111-2222']));
    }
}
