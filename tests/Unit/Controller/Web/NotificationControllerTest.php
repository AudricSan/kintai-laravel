<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\Models\WebPushSubscription;
use App\Models\Notification;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use App\Services\WebPushEndpointValidator;
use App\Http\Controllers\NotificationController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class NotificationControllerTest extends TestCase
{
    private AuthService $auth;
    private ?NotificationController $controller = null;

    protected function setUp(): void
    {
        session()->put(auth()->getName(), 5);
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(5);
        kintai_test_users(findById: fn(int $id) => $id === 5 ? ['id' => 5, 'email' => 'employee@example.com'] : null);
        kintai_test_assignments(findByUser: fn() => []);
        $this->auth = new AuthService(
        );
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): NotificationController
    {
        if ($this->controller === null) {
            $this->controller = new NotificationController(
                $this->auth,
                new WebPushEndpointValidator(static fn(string $host): array => ['142.250.74.10']),
                kintai_test_views(dirname(__DIR__, 4) . '/resources/views'),
            );
        }

        return $this->controller;
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
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
        $this->seedNotification(1, 5);
        kintai_test_user(9);
        $this->seedNotification(2, 9);

        $response = $this->controller()->deleteAll($this->makeRequest());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], Notification::where('user_id', 5)->pluck('id')->all());
        $this->assertCount(1, Notification::where('user_id', 9)->get(), 'celles des autres comptes restent');
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
        $this->seedNotification(1, 5);

        $response = $this->controller()->deleteAll($this->makeRequest(ajax: true));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, Notification::where('user_id', 5)->count());
    }

    // -------------------------------------------------------------------------
    // pushSubscribe() / pushUnsubscribe() / pushStatus() — web push natif (VAPID)
    // -------------------------------------------------------------------------

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';
    // Valeurs factices volontairement peu aléatoires : le contrôleur ne vérifie que l'alphabet et la longueur.
    private const KEYS     = ['p256dh' => 'cle-publique-de-test', 'auth' => 'secret-auth-de-test'];

    public function testPushSubscribeSavesTheSubscriptionForCurrentUser(): void
    {
        $response = $this->controller()->pushSubscribe($this->makeJsonRequest(['endpoint' => self::ENDPOINT, 'keys' => self::KEYS]));

        $this->assertSame(200, $response->getStatusCode());
        $saved = WebPushSubscription::first();
        $this->assertSame(5, (int) $saved->user_id);
        $this->assertSame(self::ENDPOINT, $saved->endpoint);
        $this->assertSame(self::KEYS['p256dh'], $saved->p256dh);
        $this->assertSame(self::KEYS['auth'], $saved->auth);
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
        try {
            $this->controller()->pushSubscribe($this->makeJsonRequest($body));
            $this->fail('abonnement invalide accepté');
        } catch (\App\Exceptions\ValidationException) {
            $this->assertSame(0, WebPushSubscription::count());
        }
    }
    /** La désinscription ne doit retirer que l'abonnement de l'utilisateur connecté (id 5 ici). */
    public function testPushUnsubscribeIsScopedToTheLoggedInUser(): void
    {
        WebPushSubscription::put(5, self::ENDPOINT, 'k', 'a', null);
        WebPushSubscription::put(9, 'https://updates.push.services.mozilla.com/other', 'k', 'a', null);

        $response = $this->controller()->pushUnsubscribe($this->makeJsonRequest(['endpoint' => self::ENDPOINT]));
        $this->controller()->pushUnsubscribe($this->makeJsonRequest(['endpoint' => 'https://updates.push.services.mozilla.com/other']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['https://updates.push.services.mozilla.com/other'], WebPushSubscription::pluck('endpoint')->all(), 'celui d\'un autre compte reste');
    }
    public function testPushUnsubscribeIsNoopWithoutEndpoint(): void
    {
        WebPushSubscription::put(5, self::ENDPOINT, 'k', 'a', null);

        $response = $this->controller()->pushUnsubscribe($this->makeJsonRequest([]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, WebPushSubscription::count());
    }
    public function testPushStatusIsTrueOnlyForTheCurrentUsersSubscription(): void
    {
        WebPushSubscription::put(5, self::ENDPOINT, 'k', 'a', null);
        WebPushSubscription::put(9, 'https://updates.push.services.mozilla.com/other', 'k', 'a', null);

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
        $this->seedNotification(10, 5, '/employee/shifts/day?start=2026-08-03');

        $response = $this->controller()->open($this->makeGetRequest(10));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/employee/shifts/day?start=2026-08-03', kintai_test_headers($response)['Location'] ?? null);
        $this->assertNotNull(Notification::find(10)->read_at, 'marquée lue');
    }

    public function testOpenFallsBackToNotificationsListWhenNoLink(): void
    {
        $this->seedNotification(10, 5);

        $response = $this->controller()->open($this->makeGetRequest(10));

        $this->assertSame('/notifications', kintai_test_headers($response)['Location'] ?? null);
    }

    /** Un lien stocké absolu/protocole-relatif (jamais généré par ce code, mais défensif) est ignoré. */
    public function testOpenIgnoresProtocolRelativeLink(): void
    {
        $this->seedNotification(10, 5, '//evil.example.com');

        $response = $this->controller()->open($this->makeGetRequest(10));

        $this->assertSame('/notifications', kintai_test_headers($response)['Location'] ?? null);
    }

    /** « /\site » est lu par les navigateurs comme « //site » : une autre origine. */
    public function testOpenIgnoresBackslashAndControlCharacterLinks(): void
    {
        foreach (['/\evil.example.com', '/ok\evil', "/ok
x", 'https://evil.example.com'] as $bad) {
            Notification::query()->delete();
            $this->seedNotification(10, 5, $bad);
            $this->controller = null;

            $response = $this->controller()->open($this->makeGetRequest(10));

            $this->assertSame('/notifications', kintai_test_headers($response)['Location'] ?? null, $bad);
        }
    }

    public function testOpenThrowsNotFoundForAnotherUsersNotification(): void
    {
        kintai_test_user(999);
        $this->seedNotification(10, 999);

        try {
            $this->controller()->open($this->makeGetRequest(10));
            $this->fail('notification d\'un autre compte ouverte');
        } catch (\App\Exceptions\NotFoundException) {
            $this->assertNull(Notification::find(10)->read_at, 'non marquée lue');
        }
    }

    public function testOpenThrowsNotFoundWhenNotificationDoesNotExist(): void
    {
        $this->expectException(\App\Exceptions\NotFoundException::class);
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
        $this->seedNotification(39, 5, null, 'ancienne', true);
        $this->seedNotification(41, 5, null, 'a');
        $this->seedNotification(43, 5, null, 'déjà lue', true);

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
        foreach ([38, 39, 40] as $id) {
            $this->seedNotification($id, 5);
        }

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
        $this->seedNotification(40, 5, null, 'Shift <b>');

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

        try {
            $data = $this->json($this->controller()->poll($this->makePollRequest(['after_id' => '40', 'count' => '2'])));
        } finally {
            $_GET = [];
        }

        $this->assertStringNotContainsString('notif-entry', $data['panel_html']);
        $this->assertStringContainsString('notif-dropdown__empty', $data['panel_html']);
        $this->assertStringContainsString('notif-dropdown__footer', $data['panel_html']);
    }

    /** Notification de test dans la table (type valide, non lue sauf demande). */
    private function seedNotification(int $id, int $userId, ?string $link = null, string $body = 'x', bool $read = false): void
    {
        Notification::create([
            'id' => $id, 'user_id' => $userId, 'type' => 'shift_assigned', 'body' => $body, 'link' => $link,
            'read_at' => $read ? date('Y-m-d H:i:s') : null, 'created_at' => '2026-10-19 10:00:00',
        ]);
    }
}
