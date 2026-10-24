<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
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
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvatarImageOptimizer;
use kintai\UI\Controller\Web\AuthController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/** Déconnexion : l'abonnement web push du navigateur (adresse envoyée avec le formulaire) est supprimé. */
final class AuthControllerLogoutPushTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_user_id'] = 9;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
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
        $auth = new AuthService($users, $storeUsers, $stores, $this->createStub(RoleRepositoryInterface::class), $roleAssignments, $this->createStub(RememberTokenRepositoryInterface::class));

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

        $response = $this->controller($subscriptions)->logout(new Request());

        $this->assertSame(302, $response->status());
    }

    public function testLogoutWithoutEndpointDeletesNothing(): void
    {
        $subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $subscriptions->expects($this->never())->method('deleteByEndpoint');
        $_POST = ['push_endpoint' => ''];

        $this->controller($subscriptions)->logout(new Request());
    }

    public function testLogoutStillSucceedsWhenTheCleanupFails(): void
    {
        $subscriptions = $this->createStub(WebPushSubscriptionRepositoryInterface::class);
        $subscriptions->method('deleteByEndpoint')->willThrowException(new \RuntimeException('table absente'));
        $_POST = ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'];

        $response = $this->controller($subscriptions)->logout(new Request());

        $this->assertSame(302, $response->status());
    }
}
