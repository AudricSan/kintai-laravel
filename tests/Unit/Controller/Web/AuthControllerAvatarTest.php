<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Exceptions\NotFoundException;
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
use PHPUnit\Framework\TestCase;

final class AuthControllerAvatarTest extends TestCase
{
    private AuthController $controller;
    private $users;

    protected function setUp(): void
    {
        session()->put(auth()->getName(), 9);

        $this->users = $this->createMock(UserRepositoryInterface::class);

        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        kintai_test_assignments(findByUser: fn() => []);
        $rememberTokens = $this->createStub(UserRepositoryInterface::class);

        $auth = new AuthService($this->users, $storeUsers, $stores, $rememberTokens);

        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $this->users,
            $stores,
            $storeUsers,
            $this->createStub(LanguageRepositoryInterface::class),
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
        $this->users->method('findById')->with(9)->willReturn(['id' => 9]);

        $response = $this->controller->uploadAvatar(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('avatar_invalid', (string) session('error'));
    }

    public function testRemoveAvatarRedirectsWithoutTouchingAnythingWhenNoAvatarSet(): void
    {
        $this->users->method('findById')->with(9)->willReturn(['id' => 9, 'avatar_path' => null]);
        $this->users->expects($this->never())->method('save');

        $response = $this->controller->removeAvatar(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testAvatarThrowsNotFoundWhenTargetHasNoPicture(): void
    {
        $this->users->method('findById')->with(42)->willReturn(['id' => 42, 'avatar_path' => null]);

        $request = Request::capture();
        $request->setRouteParams(['employee' => '42']);

        $this->expectException(NotFoundException::class);
        $this->controller->avatar($request);
    }

    public function testAvatarThrowsNotFoundForUnknownUser(): void
    {
        $this->users->method('findById')->with(999)->willReturn(null);

        $request = Request::capture();
        $request->setRouteParams(['employee' => '999']);

        $this->expectException(NotFoundException::class);
        $this->controller->avatar($request);
    }
}
