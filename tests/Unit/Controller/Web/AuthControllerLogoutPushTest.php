<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Models\Availability;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use App\Models\WebPushSubscription;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
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

    private function controller(): AuthController
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(['id' => 9, 'is_active' => 1, 'deleted_at' => null]);
        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $auth = new AuthService($users, $storeUsers, $stores, $this->createStub(RoleRepositoryInterface::class), $roleAssignments);

        return new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $stores,
            $storeUsers,
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            null,
            null,
            null,
        );
    }

    public function testLogoutDeletesThisBrowsersSubscriptionForThisUserOnly(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        WebPushSubscription::put(9, 'https://fcm.googleapis.com/fcm/send/abc', 'k', 'a', null);
        WebPushSubscription::put(9, 'https://fcm.googleapis.com/fcm/send/autre-navigateur', 'k', 'a', null);
        $_POST = ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'];

        $response = $this->controller()->logout(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/autre-navigateur'], WebPushSubscription::pluck('endpoint')->all());
    }

    public function testLogoutWithoutEndpointDeletesNothing(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        WebPushSubscription::put(9, 'https://fcm.googleapis.com/fcm/send/abc', 'k', 'a', null);
        $_POST = ['push_endpoint' => ''];

        $this->controller()->logout(Request::capture());

        $this->assertSame(1, WebPushSubscription::count());
    }

    public function testLogoutStillSucceedsWhenTheCleanupFails(): void
    {
        kintai_test_database(); // sans migrations : la table des abonnements n'existe pas
        $_POST = ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'];

        $response = $this->controller()->logout(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }
}
