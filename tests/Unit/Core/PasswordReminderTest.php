<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Container;
use kintai\Core\Middleware\PasswordReminderMiddleware;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mot de passe par défaut « 0000 » ou de moins de 8 caractères : l'application reste accessible, mais un bandeau
 * le rappelle sur chaque page et une fenêtre s'ouvre à chaque connexion (décision du 01/10/2026, qui remplace le
 * blocage introduit après l'audit du 30/09/2026).
 */
final class PasswordReminderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $row;
    private AuthService $auth;
    private Container $container;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        $this->row = [
            'id'            => 7,
            'email'         => 'emp@example.test',
            'is_active'     => 1,
            'deleted_at'    => null,
            'password_hash' => $this->hash(PasswordPolicy::DEFAULT_PASSWORD),
        ];

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn() => $this->row);
        $users->method('findByEmail')->willReturnCallback(fn() => $this->row);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);

        $this->auth = new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $this->container = new Container();
        $this->container->instance(AuthService::class, $this->auth);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    private function request(string $uri, string $method = 'GET', array $server = []): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php'] + $server;
        $_GET = $_POST = $_COOKIE = $_FILES = [];

        return new Request();
    }

    private function through(string $uri, string $method = 'GET', array $server = []): Response
    {
        $middleware = new PasswordReminderMiddleware($this->container);

        return $middleware->handle($this->request($uri, $method, $server), fn() => Response::json(['ok' => true]));
    }

    public function testDefaultPasswordMustBeChangedAfterLogin(): void
    {
        $this->assertTrue($this->auth->attempt('emp@example.test', '0000'));
        $this->assertTrue($this->auth->mustChangePassword());
    }

    public function testShortPasswordMustBeChangedAtNextLogin(): void
    {
        $this->row['password_hash'] = $this->hash('abc123');
        $this->assertTrue($this->auth->attempt('emp@example.test', 'abc123'));
        $this->assertTrue($this->auth->mustChangePassword());
    }

    public function testStrongPasswordIsNotAskedToChange(): void
    {
        $strong = bin2hex(random_bytes(6));
        $this->row['password_hash'] = $this->hash($strong);
        $this->assertTrue($this->auth->attempt('emp@example.test', $strong));
        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testSessionWithoutPlaintextStillDetectsDefaultPassword(): void
    {
        // Session ouverte sans passer par attempt() (cookie « rester connecté », ancienne session).
        $_SESSION['auth_user_id'] = 7;
        $this->assertTrue($this->auth->mustChangePassword());
    }

    public function testSessionWithoutPlaintextAcceptsOtherPassword(): void
    {
        $this->row['password_hash'] = $this->hash(bin2hex(random_bytes(6)));
        $_SESSION['auth_user_id'] = 7;
        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testNotLoggedInIsNeverAskedToChange(): void
    {
        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testChangingPasswordClearsTheRequirement(): void
    {
        $this->auth->attempt('emp@example.test', '0000');
        $this->assertTrue($this->auth->mustChangePassword());

        $this->row['password_hash'] = $this->hash(bin2hex(random_bytes(6)));
        $this->auth->refreshSessionAfterPasswordChange($this->row);

        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testAdminResettingToDefaultPasswordRevokesTheSession(): void
    {
        $strong = bin2hex(random_bytes(6));
        $this->row['password_hash'] = $this->hash($strong);
        $this->auth->attempt('emp@example.test', $strong);
        $this->assertFalse($this->auth->mustChangePassword());

        // Un admin remet « 0000 » : l'empreinte change, la session est révoquée (l'utilisateur devra
        // se reconnecter, et sera alors forcé de changer).
        $this->row['password_hash'] = $this->hash('0000');
        $this->assertFalse($this->auth->mustChangePassword());
        $this->assertFalse($this->auth->check());
    }

    /** @return array{0: Response, 1: bool, 2: bool} réponse, bandeau, fenêtre */
    private function page(string $uri, string $method = 'GET', array $server = []): array
    {
        $view = new ViewRenderer(sys_get_temp_dir());
        $this->container->instance(ViewRenderer::class, $view);
        $response = $this->through($uri, $method, $server);

        return [$response, (bool) $view->get('password_change_recommended'), (bool) $view->get('password_reminder_popup')];
    }

    /** @return array<string, array{string, string}> */
    public static function anyPage(): array
    {
        return [
            'planning'       => ['/admin/shifts/timeline', 'GET'],
            'export profil'  => ['/profile/export', 'GET'],
            'enregistrement' => ['/admin/shifts/create', 'POST'],
        ];
    }

    /** 01/10/2026 : le changement n'est plus imposé ; l'application reste entièrement accessible. */
    #[DataProvider('anyPage')]
    public function testWeakPasswordNeverBlocksTheApplication(string $uri, string $method): void
    {
        $this->auth->attempt('emp@example.test', '0000');

        [$response] = $this->page($uri, $method);

        $this->assertSame(200, $response->status());
    }

    public function testBannerIsShownOnEveryPageWhilePasswordIsWeak(): void
    {
        $this->auth->attempt('emp@example.test', '0000');

        foreach (['/employee', '/admin/shifts/timeline', '/profile'] as $uri) {
            [, $banner] = $this->page($uri);
            $this->assertTrue($banner, $uri);
        }
    }

    public function testPopupIsShownOncePerLogin(): void
    {
        $this->auth->attempt('emp@example.test', '0000');

        [, , $first]  = $this->page('/employee');
        [, , $second] = $this->page('/employee/shifts');
        $this->assertTrue($first, 'première page après la connexion');
        $this->assertFalse($second, 'pas à chaque page');

        // Nouvelle connexion : la fenêtre revient.
        $this->auth->logout();
        $this->auth->attempt('emp@example.test', '0000');
        [, , $again] = $this->page('/employee');
        $this->assertTrue($again, 'à chaque connexion');
    }

    public function testPopupIsNotConsumedByBackgroundRequests(): void
    {
        $this->auth->attempt('emp@example.test', '0000');

        [, , $ajax] = $this->page('/notifications/poll', 'GET', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        [, , $post] = $this->page('/admin/shifts/create', 'POST');
        [, , $page] = $this->page('/employee');

        $this->assertFalse($ajax);
        $this->assertFalse($post);
        $this->assertTrue($page, 'la fenêtre attend la première vraie page');
    }

    public function testSessionRestoredWithoutPasswordAlsoShowsThePopupForTheDefaultPassword(): void
    {
        // Session ouverte sans mot de passe en clair (cookie « rester connecté ») : même effet que bindSession().
        $row = $this->row;
        (fn() => $this->bindSession($row))->call($this->auth);

        [, $banner, $popup] = $this->page('/employee');
        $this->assertTrue($banner);
        $this->assertTrue($popup);
    }

    public function testStrongPasswordGetsNeitherBannerNorPopup(): void
    {
        $strong = bin2hex(random_bytes(6));
        $this->row['password_hash'] = $this->hash($strong);
        $this->auth->attempt('emp@example.test', $strong);

        [$response, $banner, $popup] = $this->page('/employee');
        $this->assertSame(200, $response->status());
        $this->assertFalse($banner);
        $this->assertFalse($popup);
    }

    public function testAnonymousVisitorsAreLeftAlone(): void
    {
        [$response, $banner, $popup] = $this->page('/login');
        $this->assertSame(200, $response->status());
        $this->assertFalse($banner);
        $this->assertFalse($popup);
    }
}
