<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\OwnerGuard;
use App\Auth\PermissionService;
use Illuminate\Container\Container;
use App\Exceptions\ForbiddenException;
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

    protected function setUp(): void
    {
kintai_test_database();        kintai_test_migrate();        kintai_test_fk_off();
        kintai_test_assignments(findByUser: fn(int $uid): array => match ($uid) {
            self::OWNER   => [['id' => 1, 'user_id' => self::OWNER, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null]],
            self::MANAGER => [['id' => 2, 'user_id' => self::MANAGER, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => self::STORE_A]],
            default       => [],
        }, findByScope: fn() => [
            ['id' => 1, 'user_id' => self::OWNER, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn(int $id): array => $id === 10
            ? ['id' => 10, 'is_system' => 1]
            : ['id' => 20, 'is_system' => 0], getPermissions: static fn() => ['employees.view', 'employees.update', 'employees.delete'], getGlobalPermissionKeys: static fn() => []);

        $this->controller = new AdminUserController(
            kintai_test_views(sys_get_temp_dir()),
            new AuditLogger(),
            new RoleAssignmentSyncService(),
            new PermissionService(),
            new PlanLimitService(
                new LicenseClientService(['base_url' => '', 'api_key' => '']),
            ),
            new OwnerGuard(new PermissionService()),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function asManager(int $targetId, array $post = []): Request
    {
        $_POST = $post;
        $req = Request::capture();
        $req->setRouteParams(['employee' => (string) $targetId]);
        $req->attributes->set('auth_user', ['id' => self::MANAGER, 'is_admin' => false]);
        $req->attributes->set('managed_store_ids', [self::STORE_A]);
        return $req;
    }

    private function target(int $id, int $storeId): void
    {
        kintai_test_users(findById: fn() => ['id' => $id, 'email' => 'x@example.com']);
        kintai_test_store_users(findByUser: fn() => [['store_id' => $storeId]]);
    }

    public function testManagerCannotResetOwnerPassword(): void
    {
        // Même membre du magasin du gérant, un Owner reste intouchable pour un non-Owner.
        $this->target(self::OWNER, self::STORE_A);
        $this->expectException(ForbiddenException::class);
        $this->controller->resetPassword($this->asManager(self::OWNER));
    }

    public function testManagerCannotResetPasswordOfAnotherStoreEmployee(): void
    {
        $this->target(30, self::STORE_B);
        $this->expectException(ForbiddenException::class);
        $this->controller->resetPassword($this->asManager(30));
    }

    public function testManagerCannotUpdateAnotherStoreEmployee(): void
    {
        $this->target(30, self::STORE_B);
        $this->expectException(ForbiddenException::class);
        $this->controller->updateUser($this->asManager(30, ['email' => 'x@example.com']));
    }

    public function testManagerCannotDeleteAnotherStoreEmployee(): void
    {
        kintai_test_store_users(findByUser: fn(int $id) => $id === 30 ? [['store_id' => self::STORE_B]] : []);
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
        $response = $this->controller->resetPassword($this->asManager(31));

        $this->assertSame(302, $response->getStatusCode());
    }

    // ------------------------------------------- actions sur soi-même (audit M13)

    public function testManagerCannotDeleteTheirOwnAccountFromTheAdmin(): void
    {
        $this->target(self::MANAGER, self::STORE_A);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->controller->deleteUser($this->asManager(self::MANAGER));
    }

    public function testManagerCannotDeactivateThemselvesFromTheAdmin(): void
    {
        kintai_test_users(findById: fn() => ['id' => self::MANAGER, 'email' => 'm@example.com', 'is_active' => 1]);
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::STORE_A]]);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        // Sélecteur « actif » sur « inactif » (un champ absent, lui, laisse l'état inchangé : audit M12).
        $this->controller->updateUser($this->asManager(self::MANAGER, ['email' => 'm@example.com', 'first_name' => 'M', 'last_name' => 'G', 'is_active' => '0']));
    }
}
