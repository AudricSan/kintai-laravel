<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Repositories\AvailabilityRepositoryInterface;
use App\Repositories\IcalTokenRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserNavPrefsRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\WebPushSubscriptionRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use App\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

/** Déconnexion : l'abonnement web push du navigateur (adresse envoyée avec le formulaire) est supprimé. */
final class AuthControllerLogoutPushTest extends TestCase
{
    protected function setUp(): void
    {
        session()->put(auth()->getName(), 9);
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    private function controller(?WebPushSubscriptionRepositoryInterface $subscriptions): AuthController
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(['id' => 9, 'is_active' => 1, 'deleted_at' => null]);
        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $auth = new AuthService($users, $storeUsers, $stores, $this->createStub(RoleRepositoryInterface::class), $roleAssignments);

        return new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $stores,
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            null,
            null,
            null,
            $subscriptions,
        );
    }

    public function testLogoutDeletesThisBrowsersSubscriptionForThisUserOnly(): void
    {
        $subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $subscriptions->expects($this->once())->method('deleteByEndpoint')->with('https://fcm.googleapis.com/fcm/send/abc', 9);
        $_POST = ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'];

        $response = $this->controller($subscriptions)->logout(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testLogoutWithoutEndpointDeletesNothing(): void
    {
        $subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $subscriptions->expects($this->never())->method('deleteByEndpoint');
        $_POST = ['push_endpoint' => ''];

        $this->controller($subscriptions)->logout(Request::capture());
    }

    public function testLogoutStillSucceedsWhenTheCleanupFails(): void
    {
        $subscriptions = $this->createStub(WebPushSubscriptionRepositoryInterface::class);
        $subscriptions->method('deleteByEndpoint')->willThrowException(new \RuntimeException('table absente'));
        $_POST = ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'];

        $response = $this->controller($subscriptions)->logout(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }
}
