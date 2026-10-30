<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Models\ApiToken;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
        session()->put(auth()->getName(), self::USER_ID);
        kintai_test_database();
        kintai_test_migrate();
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
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
        kintai_test_assignments(findByUser: fn() => $owner
            ? [['id' => 1, 'user_id' => self::USER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]]
            : []);
        kintai_test_roles(findById: fn() => ['id' => 10, 'is_system' => 1]);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $auth = new AuthService(
            $this->users,
            $storeUsers,
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
        );

        return new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $this->users,
            $this->createStub(StoreRepositoryInterface::class),
            $storeUsers,
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            new CredentialRevoker($this->createStub(UserRepositoryInterface::class)),
            new UserAnonymizer(
                $this->users,
                new CredentialRevoker($this->createStub(UserRepositoryInterface::class)),
                sys_get_temp_dir(),
            ),
        );
    }

    public function testAnOwnerCannotDeleteTheirOwnAccount(): void
    {
        $controller = $this->controller(true);
        $this->users->expects($this->never())->method('save');

        $response = $controller->deleteAccount(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $location = (string) ($response->headers->get('Location', ''));
        $this->assertStringContainsString('owner_cannot_delete', (string) session('error'));
    }

    public function testANonOwnerStillCanDeleteTheirAccount(): void
    {
        $controller = $this->controller(false);
        $this->users->expects($this->atLeastOnce())->method('save')->willReturnArgument(0);

        $controller->deleteAccount(Request::capture());
    }
}
