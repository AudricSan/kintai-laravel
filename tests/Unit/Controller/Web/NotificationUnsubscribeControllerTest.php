<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Middleware\CsrfMiddleware;
use kintai\Core\Repositories\DatabaseNotificationPreferenceRepository;
use kintai\Core\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationTypeRegistry;
use kintai\Core\Services\UnsubscribeTokens;
use kintai\Domain\Eloquent\NotificationPreference;
use kintai\Tests\Support\InMemoryAppSettings;
use kintai\UI\Controller\Web\NotificationUnsubscribeController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 4));
}

/** Désabonnement par lien signé : jamais sans signature valide, jamais par un simple affichage. */
final class NotificationUnsubscribeControllerTest extends TestCase
{
    private UnsubscribeTokens $tokens;
    private NotificationPreferenceService $prefs;
    private NotificationUnsubscribeController $controller;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->getSchemaBuilder()->create('notification_preferences', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->unique(['user_id', 'type', 'channel']);
        });

        session()->put('_token', 'test-token');
        $router = kintai_test_registrar();
        $router->post('/notifications/unsubscribe', ['X', 'y'], name: 'notifications.unsubscribe.confirm');
        $router->get('/login', ['X', 'y'], name: 'auth.login');
        // Pied de page de la mise en page invité.
        foreach (['admin.update', 'docs.index', 'employee.dashboard', 'home', 'legal.license', 'legal.mentions', 'legal.terms', 'privacy'] as $i => $name) {
            $router->get('/page-' . $i, ['X', 'y'], name: $name);
        }

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $this->prefs  = new NotificationPreferenceService(
            new DatabaseNotificationPreferenceRepository(),
            new NotificationTypeRegistry(new ExtensionRegistry($manager)),
            $this->createStub(UserRepositoryInterface::class),
        );
        $this->tokens = new UnsubscribeTokens(new InMemoryAppSettings());
        $view = new ViewRenderer(dirname(__DIR__, 4) . '/app/UI/View');
        $view->share('BASE_URL', '');
        $this->controller = new NotificationUnsubscribeController($view, $this->tokens, $this->prefs);
    }

    protected function tearDown(): void
    {
        session()->forget('_token');
        $_GET = [];
    }

    private function request(array $query): Request
    {
        $_GET = $query;
        return Request::capture();
    }

    /** @return array<string, string> paramètres d'un lien valide */
    public function link(int $user = 7, string $type = 'shift_assigned', string $channel = 'email'): array
    {
        return ['u' => (string) $user, 't' => $type, 'c' => $channel, 's' => $this->tokens->sign($user, $type, $channel)];
    }

    public function testShowDisplaysAConfirmationButNeverUnsubscribes(): void
    {
        $response = $this->controller->show($this->request($this->link()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<form method="POST"', $response->getContent());
        $this->assertStringContainsString('notif_unsub_button', $response->getContent());
        $this->assertSame(0, NotificationPreference::count(), 'un affichage (aperçu de messagerie, antivirus) ne désabonne personne');
    }

    public function testConfirmTurnsTheEmailOffForThatTypeOnly(): void
    {
        $response = $this->controller->confirm($this->request($this->link()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('notif_unsub_done_hint', $response->getContent());
        $this->assertFalse($this->prefs->isEnabled(7, 'shift_assigned', 'email'));
        $this->assertTrue($this->prefs->isEnabled(7, 'shift_deleted', 'email'), 'les autres types ne changent pas');
        $this->assertTrue($this->prefs->isEnabled(7, 'shift_assigned', 'push'), 'le push ne change pas');
        $this->assertTrue($this->prefs->isEnabled(8, 'shift_assigned', 'email'), 'les autres comptes ne changent pas');
    }

    public function testConfirmingTwiceIsHarmless(): void
    {
        $this->controller->confirm($this->request($this->link()));
        $response = $this->controller->confirm($this->request($this->link()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, NotificationPreference::count());
    }

    /** @return array<string, array{callable(self): array}> */
    public static function forgedLinks(): array
    {
        return [
            'signature d\'un autre utilisateur' => [fn(self $t) => ['u' => '8'] + $t->link(7)],
            'signature d\'un autre type'        => [fn(self $t) => ['t' => 'shift_deleted'] + $t->link(7, 'shift_assigned')],
            'canal push'                        => [fn(self $t) => $t->link(7, 'shift_assigned', 'push')],
            'signature absente'                 => [fn(self $t) => ['s' => ''] + $t->link()],
            'signature modifiée'                => [fn(self $t) => ['s' => str_repeat('0', 64)] + $t->link()],
            'aucun paramètre'                   => [fn(self $t) => []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgedLinks')]
    public function testAForgedLinkChangesNothing(callable $build): void
    {
        $query = $build($this);

        $shown     = $this->controller->show($this->request($query));
        $confirmed = $this->controller->confirm($this->request($query));

        $this->assertStringContainsString('notif_unsub_invalid', $shown->getContent());
        $this->assertSame(400, $confirmed->getStatusCode());
        $this->assertSame(0, NotificationPreference::count());
    }

    // --- CSRF : le POST d'un client e-mail n'a pas de session ----------------------------------------------------

    private function csrfOutcome(string $uri): int
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = $uri;
        $_POST = [];
        $request = Request::capture();
        session()->put('_token', 'attendu');

        return (new CsrfMiddleware())->handle($request, fn() => response()->noContent())->getStatusCode();
    }

    public function testTheUnsubscribeEndpointIsTheOnlyPostWithoutCsrfToken(): void
    {
        $this->assertSame(204, $this->csrfOutcome('/notifications/unsubscribe?u=7'));
        // Toute autre adresse reste protégée : jeton absent → connexion ou page 419.
        $this->assertNotSame(204, $this->csrfOutcomeSafe('/notifications/read-all'));
    }

    private function csrfOutcomeSafe(string $uri): int
    {
        try {
            return $this->csrfOutcome($uri);
        } catch (\Throwable) {
            return 419;
        }
    }
}
