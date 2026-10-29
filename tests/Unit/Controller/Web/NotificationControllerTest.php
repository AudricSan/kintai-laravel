<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use kintai\Core\Auth\AuthService;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use kintai\Core\Repositories\NotificationRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use kintai\Core\Services\WebPushEndpointValidator;
use kintai\UI\Controller\Web\NotificationController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class NotificationControllerTest extends TestCase
{
    private NotificationRepositoryInterface&Stub $notifications;
    private WebPushSubscriptionRepositoryInterface&Stub $subscriptions;
    private RoleAssignmentRepositoryInterface&Stub $roleAssignments;
    private AuthService $auth;
    private ?NotificationController $controller = null;

    protected function setUp(): void
    {
        session()->put('auth_user_id', 5);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(5)->willReturn(['id' => 5, 'email' => 'employee@example.com']);

        $this->roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->roleAssignments->method('findByUser')->willReturn([]);

        $this->auth = new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->roleAssignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $this->notifications = $this->createStub(NotificationRepositoryInterface::class);
        $this->subscriptions = $this->createStub(WebPushSubscriptionRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): NotificationController
    {
        if ($this->controller === null) {
            $this->controller = new NotificationController(
                $this->auth,
                $this->notifications,
                $this->subscriptions,
                new WebPushEndpointValidator(static fn(string $host): array => ['142.250.74.10']),
                new ViewRenderer(dirname(__DIR__, 4) . '/app/UI/View'),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        session()->forget('auth_user_id');
        $_POST = [];
    }

    private function makeRequest(bool $ajax = false): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        if ($ajax) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        } else {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }
        return Request::capture();
    }

    /** Simule un corps JSON (php://input non mockable en test unitaire) — même technique que Api/V1/AuthControllerTest. */
    private function makeJsonRequest(array $json): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $req = Request::capture();
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($json));
        return $req;
    }

    /**
     * Régression : marquer comme lu ne retire pas les notifications de la liste,
     * il faut un moyen de tout supprimer d'un coup pour l'utilisateur courant.
     */
    public function testDeleteAllCallsRepositoryForCurrentUserOnly(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->once())->method('deleteAllForUser')->with(5);

        $response = $this->controller()->deleteAll($this->makeRequest());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testRedirectsKeepTheSubfolderPrefixWhenInstalledInASubfolder(): void
    {
        $previous = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/Kintai/index.php';
        try {
            $response = $this->controller()->deleteAll($this->makeRequest());
        } finally {
            $previous === null ? $_SERVER['SCRIPT_NAME'] = '/index.php' : $_SERVER['SCRIPT_NAME'] = $previous;
        }

        $location = (string) ($response->headers->get('Location', ''));
        $this->assertSame('/Kintai/notifications', $location);
    }

    public function testDeleteAllReturnsJsonForAjaxRequests(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->once())->method('deleteAllForUser')->with(5);

        $response = $this->controller()->deleteAll($this->makeRequest(ajax: true));

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // pushSubscribe() / pushUnsubscribe() / pushStatus() — web push natif (VAPID)
    // -------------------------------------------------------------------------

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';
    // Valeurs factices volontairement peu aléatoires : le contrôleur ne vérifie que l'alphabet et la longueur.
    private const KEYS     = ['p256dh' => 'cle-publique-de-test', 'auth' => 'secret-auth-de-test'];

    public function testPushSubscribeSavesTheSubscriptionForCurrentUser(): void
    {
        $this->subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $this->subscriptions->expects($this->once())->method('save')
            ->with(5, self::ENDPOINT, self::KEYS['p256dh'], self::KEYS['auth'], $this->anything());

        $response = $this->controller()->pushSubscribe($this->makeJsonRequest(['endpoint' => self::ENDPOINT, 'keys' => self::KEYS]));

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, array{array}> */
    public static function invalidSubscriptions(): array
    {
        return [
            'sans adresse'            => [['keys' => self::KEYS]],
            'adresse refusée'         => [['endpoint' => 'http://fcm.googleapis.com/x', 'keys' => self::KEYS]],
            'sans clés'               => [['endpoint' => self::ENDPOINT]],
            'clé p256dh vide'         => [['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => '', 'auth' => 'abc']]],
            'clé auth invalide'       => [['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'abc', 'auth' => '<script>']]],
            'clés pas des tableaux'   => [['endpoint' => self::ENDPOINT, 'keys' => 'abc']],
        ];
    }

    #[DataProvider('invalidSubscriptions')]
    public function testPushSubscribeRejectsInvalidSubscription(array $body): void
    {
        $this->subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $this->subscriptions->expects($this->never())->method('save');

        $this->expectException(\kintai\Core\Exceptions\ValidationException::class);
        $this->controller()->pushSubscribe($this->makeJsonRequest($body));
    }

    /** La désinscription ne doit retirer que l'abonnement de l'utilisateur connecté (id 5 ici). */
    public function testPushUnsubscribeIsScopedToTheLoggedInUser(): void
    {
        $this->subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $this->subscriptions->expects($this->once())->method('deleteByEndpoint')->with(self::ENDPOINT, 5);

        $response = $this->controller()->pushUnsubscribe($this->makeJsonRequest(['endpoint' => self::ENDPOINT]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPushUnsubscribeIsNoopWithoutEndpoint(): void
    {
        $this->subscriptions = $this->createMock(WebPushSubscriptionRepositoryInterface::class);
        $this->subscriptions->expects($this->never())->method('deleteByEndpoint');

        $response = $this->controller()->pushUnsubscribe($this->makeJsonRequest([]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPushStatusIsTrueOnlyForTheCurrentUsersSubscription(): void
    {
        $this->subscriptions->method('findByEndpoint')->willReturnMap([
            [self::ENDPOINT, ['id' => 1, 'user_id' => 5]],
            ['https://updates.push.services.mozilla.com/other', ['id' => 2, 'user_id' => 9]],
        ]);

        $mine   = json_decode($this->controller()->pushStatus($this->makeJsonRequest(['endpoint' => self::ENDPOINT]))->getContent(), true);
        $theirs = json_decode($this->controller()->pushStatus($this->makeJsonRequest(['endpoint' => 'https://updates.push.services.mozilla.com/other']))->getContent(), true);
        $unknown = json_decode($this->controller()->pushStatus($this->makeJsonRequest(['endpoint' => 'https://fcm.googleapis.com/inconnu']))->getContent(), true);
        $empty  = json_decode($this->controller()->pushStatus($this->makeJsonRequest([]))->getContent(), true);

        $this->assertSame(['subscribed' => true], $mine);
        $this->assertSame(['subscribed' => false], $theirs, 'abonnement d\'un autre compte');
        $this->assertSame(['subscribed' => false], $unknown);
        $this->assertSame(['subscribed' => false], $empty);
    }
    // -------------------------------------------------------------------------
    // open() — clic sur une notification : marquer lu + rediriger vers sa cible
    // -------------------------------------------------------------------------

    private function makeGetRequest(int $id): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME']    = '/index.php';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $req = Request::capture();
        $req->setRouteParams(['id' => $id]);
        return $req;
    }

    /**
     * Régression : une notification "nouveau shift" ne disait pas lequel et ne
     * menait nulle part. open() doit marquer lu puis rediriger vers le lien stocké
     * (voir NotificationService::notify()).
     */
    /** Audit n°7 : la destination postée n'était pas filtrée (redirection ouverte). */
    #[\PHPUnit\Framework\Attributes\DataProvider('markReadRedirects')]
    public function testMarkReadOnlyRedirectsToInternalPaths(string $posted, string $expectedSuffix): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/notifications/3/read', 'SCRIPT_NAME' => '/index.php'];
        $_POST   = ['redirect' => $posted];
        $req = \Illuminate\Http\Request::capture();
        $req->setRouteParams(['id' => '3']);

        $response = $this->controller()->markRead($req);

        $location = (string) ($response->headers->get('Location', ''));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith($expectedSuffix, $location);
        $this->assertStringNotContainsString('evil.example', $location);
    }

    /** @return array<string, array{string, string}> */
    public static function markReadRedirects(): array
    {
        return [
            'chemin interne'            => ['/employee/shifts', '/employee/shifts'],
            'site externe'              => ['https://evil.example/', '/notifications'],
            'origine relative au schéma' => ['//evil.example/', '/notifications'],
            'antislash'                 => ['/\evil.example/', '/notifications'],
            'vide'                      => ['', '/notifications'],
        ];
    }

    public function testOpenMarksReadAndRedirectsToStoredLink(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn([
            'id' => 10, 'user_id' => 5, 'link' => '/employee/shifts/day?start=2026-08-03',
        ]);
        $this->notifications->expects($this->once())->method('markRead')->with(10, 5);

        $response = $this->controller()->open($this->makeGetRequest(10));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/employee/shifts/day?start=2026-08-03', kintai_test_headers($response)['Location'] ?? null);
    }

    public function testOpenFallsBackToNotificationsListWhenNoLink(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10, 'user_id' => 5, 'link' => null]);
        $this->notifications->expects($this->once())->method('markRead')->with(10, 5);

        $response = $this->controller()->open($this->makeGetRequest(10));

        $this->assertSame('/notifications', kintai_test_headers($response)['Location'] ?? null);
    }

    /** Un lien stocké absolu/protocole-relatif (jamais généré par ce code, mais défensif) est ignoré. */
    public function testOpenIgnoresProtocolRelativeLink(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10, 'user_id' => 5, 'link' => '//evil.example.com']);
        $this->notifications->method('markRead');

        $response = $this->controller()->open($this->makeGetRequest(10));

        $this->assertSame('/notifications', kintai_test_headers($response)['Location'] ?? null);
    }

    /** « /\site » est lu par les navigateurs comme « //site » : une autre origine. */
    public function testOpenIgnoresBackslashAndControlCharacterLinks(): void
    {
        foreach (['/\evil.example.com', '/ok\evil', "/ok
x", 'https://evil.example.com'] as $bad) {
            $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
            $this->notifications->method('findById')->with(10)->willReturn(['id' => 10, 'user_id' => 5, 'link' => $bad]);
            $this->notifications->method('markRead');
            $this->controller = null;

            $response = $this->controller()->open($this->makeGetRequest(10));

            $this->assertSame('/notifications', kintai_test_headers($response)['Location'] ?? null, $bad);
        }
    }

    public function testOpenThrowsNotFoundForAnotherUsersNotification(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(['id' => 10, 'user_id' => 999, 'link' => null]);
        $this->notifications->expects($this->never())->method('markRead');

        $this->expectException(\kintai\Core\Exceptions\NotFoundException::class);
        $this->controller()->open($this->makeGetRequest(10));
    }

    public function testOpenThrowsNotFoundWhenNotificationDoesNotExist(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->atLeastOnce())->method('findById')->with(10)->willReturn(null);

        $this->expectException(\kintai\Core\Exceptions\NotFoundException::class);
        $this->controller()->open($this->makeGetRequest(10));
    }

    // -------------------------------------------------------------------------
    // poll() — curseur par id, panneau rafraîchi seulement si quelque chose a changé
    // -------------------------------------------------------------------------

    private function bindRouter(): void
    {
        $router = kintai_test_registrar();
        foreach (['notifications.open' => '/notifications/{id}/open', 'notifications.read_all' => '/notifications/read-all',
                  'notifications.delete_all' => '/notifications/delete-all', 'notifications.index' => '/notifications'] as $name => $path) {
            $router->get($path, [\stdClass::class, 'x'], name: $name);
        }
    }

    private function makePollRequest(array $query): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME']    = '/index.php';
        $_GET = $query;
        return Request::capture();
    }

    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return json_decode($response->getContent(), true);
    }

    public function testPollAsksRepositoryForRowsAfterTheCursorAndAdvancesIt(): void
    {
        $this->bindRouter();
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->expects($this->once())->method('findAfterId')->with(5, 40, 50)->willReturn([
            ['id' => 41, 'type' => 'shift_assigned', 'body' => 'a', 'is_read' => 0],
            ['id' => 43, 'type' => 'shift_assigned', 'body' => 'déjà lue', 'is_read' => 1],
        ]);
        $this->notifications->method('countUnread')->willReturn(1);
        $this->notifications->method('findUnreadByUser')->willReturn([]);

        try {
            $data = $this->json($this->controller()->poll($this->makePollRequest(['after_id' => '40', 'count' => '0'])));
        } finally {
            $_GET = [];
        }

        $this->assertSame(43, $data['last_id']);
        $this->assertSame(1, $data['unread_count']);
        $this->assertSame('1', $data['badge']);
        // Seules les non lues donnent un toast.
        $this->assertSame([41], array_column($data['notifications'], 'id'));
        $this->assertSame('a', $data['notifications'][0]['body']);
        $this->assertArrayHasKey('panel_html', $data);
    }

    public function testPollOmitsPanelWhenNothingChanged(): void
    {
        $this->notifications = $this->createMock(NotificationRepositoryInterface::class);
        $this->notifications->method('findAfterId')->willReturn([]);
        $this->notifications->method('countUnread')->willReturn(3);
        $this->notifications->expects($this->never())->method('findUnreadByUser');

        try {
            $data = $this->json($this->controller()->poll($this->makePollRequest(['after_id' => '40', 'count' => '3'])));
        } finally {
            $_GET = [];
        }

        $this->assertSame(40, $data['last_id']);
        $this->assertSame([], $data['notifications']);
        $this->assertArrayNotHasKey('panel_html', $data);
    }

    /** Une notification lue ailleurs (autre onglet, autre appareil) change le compteur : le panneau suit. */
    public function testPollReturnsPanelWhenUnreadCountDiffersFromTheBrowser(): void
    {
        $this->bindRouter();
        $this->notifications->method('findAfterId')->willReturn([]);
        $this->notifications->method('countUnread')->willReturn(1);
        $this->notifications->method('findUnreadByUser')->willReturn([
            ['id' => 40, 'type' => 'shift_assigned', 'body' => 'Shift <b>', 'is_read' => 0, 'created_at' => '2026-10-19 10:00:00'],
        ]);

        try {
            $data = $this->json($this->controller()->poll($this->makePollRequest(['after_id' => '40', 'count' => '2'])));
        } finally {
            $_GET = [];
        }

        $this->assertStringContainsString('notif-entry', $data['panel_html']);
        $this->assertStringContainsString('Shift &lt;b&gt;', $data['panel_html']);
        $this->assertStringContainsString('notif-entry--unread', $data['panel_html']);
    }

    /** Cloche vide (tout est lu) : message dédié, et l'historique complet reste joignable. */
    public function testPollPanelWhenEverythingIsReadStillLinksToTheHistory(): void
    {
        $this->bindRouter();
        $this->notifications->method('findAfterId')->willReturn([]);
        $this->notifications->method('countUnread')->willReturn(0);
        $this->notifications->method('findUnreadByUser')->willReturn([]);

        try {
            $data = $this->json($this->controller()->poll($this->makePollRequest(['after_id' => '40', 'count' => '2'])));
        } finally {
            $_GET = [];
        }

        $this->assertStringNotContainsString('notif-entry', $data['panel_html']);
        $this->assertStringContainsString('notif-dropdown__empty', $data['panel_html']);
        $this->assertStringContainsString('notif-dropdown__footer', $data['panel_html']);
    }
}
