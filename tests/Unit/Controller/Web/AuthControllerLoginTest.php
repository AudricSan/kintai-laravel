<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Repositories\AvailabilityRepositoryInterface;
use App\Repositories\IcalTokenRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RememberTokenRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserNavPrefsRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use App\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class AuthControllerLoginTest extends TestCase
{
    private ?AuthController $controller = null;
    private $users;

    protected function setUp(): void
    {
        session()->forget('auth_user_id');

        $this->users = $this->createStub(UserRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AuthController
    {
        if ($this->controller === null) {
            $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
            $stores          = $this->createStub(StoreRepositoryInterface::class);
            $roles           = $this->createStub(RoleRepositoryInterface::class);
            $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
            $rememberTokens = $this->createStub(RememberTokenRepositoryInterface::class);
            $auth = new AuthService($this->users, $storeUsers, $stores, $roles, $roleAssignments, $rememberTokens);
            $roleAssignments->method('findByUser')->willReturn([]);
            $this->controller = new AuthController(
                new ViewRenderer(sys_get_temp_dir()),
                $auth,
                new AuditLogger(),
                $this->users,
                $stores,
                $storeUsers,
                $this->createStub(IcalTokenRepositoryInterface::class),
                $this->createStub(UserNavPrefsRepositoryInterface::class),
                $this->createStub(AvailabilityRepositoryInterface::class),
                $this->createStub(LanguageRepositoryInterface::class),
                new AvatarImageOptimizer(),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        session()->forget('auth_user_id');
        $_POST = [];
    }

    public function testSuccessfulLoginUpdatesLastLoginAt(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $user = [
            'id'            => 9,
            'email'         => 'employee@example.test',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'is_active'     => 1,
            'deleted_at'    => null,
        ];
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('employee@example.test')->willReturn($user);
        $this->users->expects($this->atLeastOnce())->method('findById')->with(9)->willReturn($user);

        $this->users->expects($this->once())
            ->method('save')
            ->with($this->callback(function (array $data): bool {
                return ($data['id'] ?? null) === 9
                    && !empty($data['last_login_at'])
                    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['last_login_at']) === 1;
            }))
            ->willReturn($user);

        $_POST = [
            'login_mode' => 'email',
            'email'      => 'employee@example.test',
            'password'   => 'secret',
        ];
        $request = Request::capture();

        $response = $this->controller()->login($request);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testFailedLoginFlagsTheRequestForTheThrottle(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $_POST = ['login_mode' => 'email', 'email' => 'employee@example.test', 'password' => bin2hex(random_bytes(6))];
        $request = Request::capture();
        $this->controller()->login($request);

        // Lu par LoginThrottleMiddleware : seuls les échecs comptent.
        $this->assertTrue($request->attributes->get('auth_failed'));
    }

    public function testSuccessfulLoginDoesNotFlagTheRequest(): void
    {
        $password = bin2hex(random_bytes(6));
        $user = [
            'id'            => 9,
            'email'         => 'employee@example.test',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'is_active'     => 1,
            'deleted_at'    => null,
        ];
        $this->users->method('findByEmail')->willReturn($user);
        $this->users->method('findById')->willReturn($user);
        $this->users->method('save')->willReturn($user);

        $_POST = ['login_mode' => 'email', 'email' => 'employee@example.test', 'password' => $password];
        $request = Request::capture();
        $this->controller()->login($request);

        $this->assertNull($request->attributes->get('auth_failed'));
    }

    public function testFailedLoginDoesNotTouchLastLoginAt(): void
    {
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->users->expects($this->atLeastOnce())->method('findByEmail')->with('employee@example.test')->willReturn(null);
        $this->users->expects($this->never())->method('save');

        $_POST = [
            'login_mode' => 'email',
            'email'      => 'employee@example.test',
            'password'   => 'wrong',
        ];
        $request = Request::capture();

        $response = $this->controller()->login($request);

        $this->assertSame(302, $response->getStatusCode());
    }
}
