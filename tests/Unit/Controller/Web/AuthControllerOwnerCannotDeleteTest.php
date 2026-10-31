<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Models\ApiToken;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * La suppression de compte en libre-service anonymise et désactive le compte : pour un Owner, elle pourrait laisser
 * l'instance sans administrateur. Il doit d'abord céder le rôle à un autre Owner.
 */
final class AuthControllerOwnerCannotDeleteTest extends TestCase
{
    private const USER_ID = 5;

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
        kintai_test_users(findById: fn() => [
            'id' => self::USER_ID, 'email' => 'o@example.test', 'is_active' => 1, 'deleted_at' => null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $_POST = ['password' => $password];
        kintai_test_assignments(findByUser: fn() => $owner
            ? [['id' => 1, 'user_id' => self::USER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]]
            : []);
        kintai_test_roles(findById: fn() => ['id' => 10, 'is_system' => 1]);
        $auth = new AuthService(
        );

        return new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            new AvatarImageOptimizer(),
            new CredentialRevoker(),
            new UserAnonymizer(
                new CredentialRevoker(),
                sys_get_temp_dir(),
            ),
        );
    }

    public function testAnOwnerCannotDeleteTheirOwnAccount(): void
    {
        $controller = $this->controller(true);
        $response = $controller->deleteAccount(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $location = (string) ($response->headers->get('Location', ''));
        $this->assertStringContainsString('owner_cannot_delete', (string) session('error'));
    }

    public function testANonOwnerStillCanDeleteTheirAccount(): void
    {
        $controller = $this->controller(false);
        $controller->deleteAccount(Request::capture());

        $this->assertNotNull(\App\Models\User::findById(self::USER_ID)['deleted_at'], 'compte anonymisé');
    }
}
