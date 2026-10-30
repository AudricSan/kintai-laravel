<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\CredentialRevoker;
use App\Auth\UserAnonymizer;
use App\Auth\OwnerGuard;
use App\Auth\PermissionService;
use Illuminate\Container\Container;
use App\Models\ApiToken;
use App\Models\AppSetting;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\UserShiftTypeRate;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\LicenseClientService;
use Illuminate\Support\Facades\Log;
use App\Services\PlanLimitService;
use App\Services\RoleAssignmentSyncService;
use App\Http\Controllers\Staff\AdminUserController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
    use \Tests\Support\ApiTokenExpectations;

    private const TARGET_ID = 7;

    private AdminUserController $controller;

    protected function setUp(): void
    {
        kintai_test_users(findById: fn() => [
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
        // Demandeur : rôle système global (la garde d'accès à l'employé ciblé doit le laisser passer).
        kintai_test_assignments(findByUser: fn() => [['id' => 1, 'user_id' => 1, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]]);
        kintai_test_roles(findById: fn() => ['id' => 10, 'is_system' => 1]);

        $this->controller = new AdminUserController(
            kintai_test_views(sys_get_temp_dir()),
            new AuditLogger(),
            new RoleAssignmentSyncService(),
            new PermissionService(),
            new PlanLimitService(
                new LicenseClientService(['base_url' => '', 'api_key' => '']),
            ),
            new OwnerGuard(new PermissionService()),
            new CredentialRevoker(),
            new UserAnonymizer(new CredentialRevoker(), sys_get_temp_dir()),
        );
    }

    private bool $revocationExpected = false;

    protected function tearDown(): void
    {
        if ($this->revocationExpected) {
            $this->assertNull(\App\Models\User::query()->find(self::TARGET_ID)?->remember_token, 'jeton « rester connecté » révoqué');
            $this->revocationExpected = false;
        }
        $_POST = [];
    }

    private function request(array $post): Request
    {
        $_POST = $post;
        $req = Request::capture();
        $req->setRouteParams(['employee' => (string) self::TARGET_ID]);
        $req->attributes->set('auth_user', ['id' => 1, 'is_admin' => false]);
        return $req;
    }

    private function expectRevocation(): void
    {
        kintai_test_remember_token(self::TARGET_ID);
        $this->expectApiTokensRevoked(self::TARGET_ID);
        $this->revocationExpected = true;
    }

    private function expectNoRevocation(): void
    {
        $this->expectApiTokensKept(1);
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
