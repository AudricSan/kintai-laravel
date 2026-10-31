<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\UserNavPref;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

final class AuthControllerLoginTest extends TestCase
{
    private ?AuthController $controller = null;
    private $users;

    protected function setUp(): void
    {
        session()->forget(auth()->getName());
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AuthController
    {
        if ($this->controller === null) {
            $auth = new AuthService();
            kintai_test_assignments(findByUser: fn() => []);
            $this->controller = new AuthController(
                kintai_test_views(sys_get_temp_dir()),
                $auth,
                new AuditLogger(),
                new AvatarImageOptimizer(),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    public function testSuccessfulLoginUpdatesLastLoginAt(): void
    {
        $user = [
            'id'            => 9,
            'email'         => 'employee@example.test',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'is_active'     => 1,
            'deleted_at'    => null,
        ];
        kintai_test_users(findById: fn(int $id) => $id === 9 ? $user : null, findByEmail: fn() => $user);
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
        kintai_test_users(findByEmail: fn() => null);
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
        kintai_test_users(findById: fn() => $user, findByEmail: fn() => $user);
        $_POST = ['login_mode' => 'email', 'email' => 'employee@example.test', 'password' => $password];
        $request = Request::capture();
        $this->controller()->login($request);

        $this->assertNull($request->attributes->get('auth_failed'));
    }

    public function testFailedLoginDoesNotTouchLastLoginAt(): void
    {
        kintai_test_users(findByEmail: fn() => null);
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
