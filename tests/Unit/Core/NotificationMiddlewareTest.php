<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\AuthService;
use Illuminate\Container\Container;
use App\Http\Middleware\NotificationMiddleware;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Models\Notification;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\VapidKeyStore;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Couvre push_web_config, partagé à toutes les vues par ce middleware : vide tant que le web push n'est pas
 * opérationnel (clés VAPID absentes), pour que push.js et le bouton "Activer" du profil ne s'affichent nulle part ;
 * sinon la seule clé publique, jamais la clé privée.
 */
final class NotificationMiddlewareTest extends TestCase
{
    private Container $container;
    private ViewFactory $view;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(7);
        $this->container = new \Tests\Support\TestContainer();
        $this->view       = kintai_test_views(sys_get_temp_dir());
        $this->container->instance(ViewFactory::class, $this->view);

    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
    }

    private function bindAuth(?array $user): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);

        $auth = new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(UserRepositoryInterface::class),
        );
        $this->container->instance(AuthService::class, $auth);

        if ($user !== null) {
            session()->put(auth()->getName(), $user['id']);
        }
    }

    private function bindNotifications(): void
    {
        // Aucune notification : la base de test est vide.
    }

    private function noop(): \Closure
    {
        return fn(Request $req) => response()->json(['ok' => true]);
    }

    /** Réglages en mémoire ; clés VAPID présentes ou non. */
    private function bindVapid(?string $public, ?string $private = 'private-key'): void
    {
        $settings = new class($public, $private) implements AppSettingsRepositoryInterface {
            public array $values = [];

            public function __construct(?string $public, ?string $private)
            {
                $this->values = array_filter([VapidKeyStore::PUBLIC_KEY => $public, VapidKeyStore::PRIVATE_KEY => $private], fn($v) => $v !== null);
            }

            public function all(): array { return $this->values; }
            public function get(string $key): ?string { return $this->values[$key] ?? null; }
            public function set(string $key, string $value): void { $this->values[$key] = $value; }
            public function setMany(array $settings): void { $this->values = $settings + $this->values; }
        };
        $this->container->instance(VapidKeyStore::class, new VapidKeyStore($settings));
    }

    public function testPushWebConfigEmptyWhenAnonymous(): void
    {
        $this->bindVapid('public-key');
        $this->bindAuth(null);
        $this->bindNotifications();
        $middleware = new NotificationMiddleware($this->container);

        $middleware->handle(Request::capture(), $this->noop());

        $this->assertSame([], $this->view->shared('push_web_config'));
    }

    public function testPushWebConfigEmptyWhenKeysAreMissing(): void
    {
        $this->bindVapid(null, null);
        $this->bindAuth(['id' => 7]);
        $this->bindNotifications();
        $middleware = new NotificationMiddleware($this->container);

        $middleware->handle(Request::capture(), $this->noop());

        $this->assertSame([], $this->view->shared('push_web_config'));
    }

    public function testPushWebConfigExposesOnlyThePublicKeyWhenAuthenticated(): void
    {
        $this->bindVapid('public-key-789', 'private-key-SECRET');
        $this->bindAuth(['id' => 7]);
        $this->bindNotifications();
        $middleware = new NotificationMiddleware($this->container);

        $middleware->handle(Request::capture(), $this->noop());

        $this->assertSame(['vapid_key' => 'public-key-789'], $this->view->shared('push_web_config'));
    }

    public function testPushWebConfigEmptyWhenTheSettingsAreUnreachable(): void
    {
        // Aucun VapidKeyStore dans le conteneur et pas de base : le middleware ne doit pas échouer pour autant.
        kintai_test_database(); // sans migrations : les réglages sont injoignables
        $this->bindAuth(['id' => 7]);
        $this->bindNotifications();
        $middleware = new NotificationMiddleware($this->container);

        $middleware->handle(Request::capture(), $this->noop());

        $this->assertSame([], $this->view->shared('push_web_config'));
    }
    public function testDegradesGracefullyWhenNotificationRepositoryThrows(): void
    {
        $this->bindAuth(['id' => 7]);
        kintai_test_database(); // sans migrations : la table des notifications n'existe pas
        $middleware = new NotificationMiddleware($this->container);

        $response = $middleware->handle(Request::capture(), $this->noop());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->view->shared('unread_notifications_count'));
    }

    /**
     * La cloche ne montre que les non lues, mais le curseur de départ du polling est l'id de la plus récente
     * notification, lue ou non : sinon une récente déjà lue serait redemandée au premier poll.
     */
    public function testSharesLastIdOfNewestNotification(): void
    {
        $this->bindAuth(['id' => 7]);
        foreach ([[38, '2026-10-19 08:00:00', false], [40, '2026-10-19 09:00:00', false], [42, '2026-10-19 10:00:00', true]] as [$id, $at, $read]) {
            Notification::create(['id' => $id, 'user_id' => 7, 'type' => 'shift_assigned', 'body' => 'x', 'created_at' => $at, 'read_at' => $read ? $at : null]);
        }

        (new NotificationMiddleware($this->container))->handle(Request::capture(), $this->noop());

        $this->assertSame(42, $this->view->shared('notifications_last_id'));
        $this->assertSame([40, 38], array_column($this->view->shared('notifications_dropdown'), 'id'));
        $this->assertSame(2, $this->view->shared('unread_notifications_count'));
    }

    /**
     * Le poll de la cloche (toutes les 15 s) et les autres requêtes AJAX ne rendent pas la topbar : le middleware
     * ne doit pas y refaire ses requêtes.
     */
    public function testSkipsAjaxRequests(): void
    {
        $this->bindAuth(['id' => 7]);
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'notifications')) {
                $queries++;
            }
        });

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        try {
            $response = (new NotificationMiddleware($this->container))->handle(Request::capture(), $this->noop());
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $queries, 'aucune requête sur les notifications');
    }
}
