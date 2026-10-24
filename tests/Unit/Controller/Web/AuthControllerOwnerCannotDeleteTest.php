<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\CredentialRevoker;
use kintai\Core\Auth\UserAnonymizer;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvatarImageOptimizer;
use kintai\UI\Controller\Web\AuthController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * La suppression de compte en libre-service anonymise et désactive le compte : pour un Owner, elle pourrait laisser
 * l'instance sans administrateur. Il doit d'abord céder le rôle à un autre Owner.
 */
final class AuthControllerOwnerCannotDeleteTest extends TestCase
{
    private const USER_ID = 5;

    private UserRepositoryInterface&MockObject $users;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_user_id'] = self::USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
        $_POST = [];
    }

    private function controller(bool $owner): AuthController
    {
        $password = 'correct-horse-battery';
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->method('findById')->willReturn([
            'id' => self::USER_ID, 'email' => 'o@example.test', 'is_active' => 1, 'deleted_at' => null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $_POST = ['password' => $password];

        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn($owner
            ? [['id' => 1, 'user_id' => self::USER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]]
            : []);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $auth = new AuthService(
            $this->users,
            $storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $roles,
            $assignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        return new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            new CredentialRevoker($this->createStub(RememberTokenRepositoryInterface::class), $this->createStub(ApiTokenRepositoryInterface::class)),
            new UserAnonymizer(
                $this->users,
                $assignments,
                new CredentialRevoker($this->createStub(RememberTokenRepositoryInterface::class), $this->createStub(ApiTokenRepositoryInterface::class)),
                sys_get_temp_dir(),
            ),
        );
    }

    public function testAnOwnerCannotDeleteTheirOwnAccount(): void
    {
        $controller = $this->controller(true);
        $this->users->expects($this->never())->method('save');

        $response = $controller->deleteAccount(new Request());

        $this->assertSame(302, $response->status());
        $location = (string) ((fn() => $this->headers['Location'] ?? '')->call($response));
        $this->assertStringContainsString('error=owner_cannot_delete', $location);
    }

    public function testANonOwnerStillCanDeleteTheirAccount(): void
    {
        $controller = $this->controller(false);
        $this->users->expects($this->atLeastOnce())->method('save')->willReturnArgument(0);

        $controller->deleteAccount(new Request());
    }
}
