<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Exceptions\NotFoundException;
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

final class AuthControllerAvatarTest extends TestCase
{
    private AuthController $controller;
    private $users;

    protected function setUp(): void
    {
        session()->put(auth()->getName(), 9);

        kintai_test_assignments(findByUser: fn() => []);

        $auth = new AuthService();

        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    public function testUploadAvatarRedirectsWithErrorWhenNoFileProvided(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 9 ? ['id' => 9] : null);
        $response = $this->controller->uploadAvatar(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('avatar_invalid', (string) session('error'));
    }

    public function testRemoveAvatarRedirectsWithoutTouchingAnythingWhenNoAvatarSet(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 9 ? ['id' => 9, 'avatar_path' => null] : null);
        $response = $this->controller->removeAvatar(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testAvatarThrowsNotFoundWhenTargetHasNoPicture(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 42 ? ['id' => 42, 'avatar_path' => null] : null);
        $request = Request::capture();
        $request->setRouteParams(['employee' => '42']);

        $this->expectException(NotFoundException::class);
        $this->controller->avatar($request);
    }

    public function testAvatarThrowsNotFoundForUnknownUser(): void
    {
        kintai_test_users(findById: fn(int $id) => $id === 999 ? null : null);
        $request = Request::capture();
        $request->setRouteParams(['employee' => '999']);

        $this->expectException(NotFoundException::class);
        $this->controller->avatar($request);
    }
}
