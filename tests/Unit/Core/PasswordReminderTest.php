<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Auth\AuthService;
use Illuminate\Container\Container;
use App\Http\Middleware\PasswordReminderMiddleware;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mot de passe temporaire (users.must_change_password) ou de moins de 8 caractères : tant qu'il n'est pas changé, toute
 * page web renvoie vers le profil (audit du 31/10/2026, SEC-02), qui reste accessible avec un bandeau et une fenêtre.
 */
final class PasswordReminderTest extends TestCase
{
    private const TEMP = 'Tmp-pass-12';

    /** @var array<string, mixed> */
    private array $row;
    private AuthService $auth;
    private Container $container;

    protected function setUp(): void
    {
        session()->flush();

        $this->row = [
            'id'            => 7,
            'email'         => 'emp@example.test',
            'is_active'     => 1,
            'deleted_at'    => null,
            'password_hash' => $this->hash(self::TEMP),
            'must_change_password' => 1,
        ];
        kintai_test_users(findById: fn() => $this->row, findByEmail: fn() => $this->row);
        kintai_test_assignments(findByUser: fn() => []);
        $this->auth = new AuthService(
        );

        $this->container = new \Tests\Support\TestContainer();
        $this->container->instance(AuthService::class, $this->auth);
    }

    protected function tearDown(): void
    {
        session()->flush();
    }

    private function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    private function request(string $uri, string $method = 'GET', array $server = []): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php'] + $server;
        $_GET = $_POST = $_COOKIE = $_FILES = [];

        return Request::capture();
    }

    private function through(string $uri, string $method = 'GET', array $server = []): Response
    {
        $middleware = new PasswordReminderMiddleware($this->container);

        return $middleware->handle($this->request($uri, $method, $server), fn() => response()->json(['ok' => true]));
    }

    public function testDefaultPasswordMustBeChangedAfterLogin(): void
    {
        $this->assertTrue($this->auth->attempt('emp@example.test', self::TEMP));
        $this->assertTrue($this->auth->mustChangePassword());
    }

    public function testShortPasswordMustBeChangedAtNextLogin(): void
    {
        $this->row['password_hash'] = $this->hash('abc123');
        $this->row['must_change_password'] = 0;
        kintai_test_users(findById: fn() => $this->row);
        $this->assertTrue($this->auth->attempt('emp@example.test', 'abc123'));
        $this->assertTrue($this->auth->mustChangePassword());
    }

    public function testStrongPasswordIsNotAskedToChange(): void
    {
        $strong = bin2hex(random_bytes(6));
        $this->row['password_hash'] = $this->hash($strong);
        $this->row['must_change_password'] = 0;
        kintai_test_users(findById: fn() => $this->row);
        $this->assertTrue($this->auth->attempt('emp@example.test', $strong));
        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testSessionWithoutPlaintextStillDetectsATemporaryPassword(): void
    {
        // Session ouverte sans passer par attempt() (cookie « rester connecté », ancienne session).
        session()->put(auth()->getName(), 7);
        $this->assertTrue($this->auth->mustChangePassword());
    }

    public function testSessionWithoutPlaintextAcceptsOtherPassword(): void
    {
        $this->row['password_hash'] = $this->hash(bin2hex(random_bytes(6)));
        $this->row['must_change_password'] = 0;
        kintai_test_users(findById: fn() => $this->row);
        session()->put(auth()->getName(), 7);
        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testNotLoggedInIsNeverAskedToChange(): void
    {
        $this->assertFalse($this->auth->mustChangePassword());
    }

    public function testChangingPasswordClearsTheRequirement(): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);
        $this->assertTrue($this->auth->mustChangePassword());

        $this->row['password_hash'] = $this->hash(bin2hex(random_bytes(6)));
        $this->row['must_change_password'] = 0;
        kintai_test_users(findById: fn() => $this->row);
        $this->auth->refreshSessionAfterPasswordChange($this->row);

        $this->assertFalse($this->auth->mustChangePassword());
    }

    /** @return array{0: Response, 1: bool, 2: bool} réponse, bandeau, fenêtre */
    private function page(string $uri, string $method = 'GET', array $server = []): array
    {
        $view = kintai_test_views(sys_get_temp_dir());
        $this->container->instance(ViewFactory::class, $view);
        $response = $this->through($uri, $method, $server);

        return [$response, (bool) $view->shared('password_change_recommended'), (bool) $view->shared('password_reminder_popup')];
    }

    /** @return array<string, array{string, string}> */
    public static function blockedPage(): array
    {
        return [
            'planning'       => ['/admin/shifts/timeline', 'GET'],
            'tableau de bord' => ['/employee', 'GET'],
            'enregistrement' => ['/admin/shifts/create', 'POST'],
        ];
    }

    /** SEC-02 : tant que le mot de passe n'est pas changé, toute page métier renvoie vers le profil. */
    #[DataProvider('blockedPage')]
    public function testPendingPasswordChangeSendsEveryBusinessPageToTheProfile(string $uri, string $method): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);

        [$response] = $this->page($uri, $method);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/profile?tab=info', (string) $response->headers->get('Location'));
    }

    public function testTheProfileAndSignOutStayReachable(): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);

        foreach ([['/profile', 'GET'], ['/profile/password', 'POST'], ['/profile/export', 'GET'], ['/logout', 'POST'], ['/lang/fr', 'POST']] as [$uri, $method]) {
            [$response] = $this->page($uri, $method);
            $this->assertSame(200, $response->getStatusCode(), $uri);
        }
    }

    public function testABackgroundRequestGetsAJsonRefusalInsteadOfARedirect(): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);

        [$response] = $this->page('/notifications/poll', 'GET', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testBannerIsShownOnEveryPageWhilePasswordIsWeak(): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);

        foreach (['/profile', '/profile/export'] as $uri) {
            [, $banner] = $this->page($uri);
            $this->assertTrue($banner, $uri);
        }
    }

    public function testPopupIsShownOncePerLogin(): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);

        [, , $first]  = $this->page('/profile');
        [, , $second] = $this->page('/profile/export');
        $this->assertTrue($first, 'première page après la connexion');
        $this->assertFalse($second, 'pas à chaque page');

        // Nouvelle connexion : la fenêtre revient.
        $this->auth->logout();
        $this->auth->attempt('emp@example.test', self::TEMP);
        [, , $again] = $this->page('/profile');
        $this->assertTrue($again, 'à chaque connexion');
    }

    public function testPopupIsNotConsumedByBackgroundRequests(): void
    {
        $this->auth->attempt('emp@example.test', self::TEMP);

        [, , $ajax] = $this->page('/notifications/poll', 'GET', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        [, , $post] = $this->page('/admin/shifts/create', 'POST');
        [, , $page] = $this->page('/profile');

        $this->assertFalse($ajax);
        $this->assertFalse($post);
        $this->assertTrue($page, 'la fenêtre attend la première vraie page');
    }

    public function testSessionRestoredWithoutPasswordAlsoShowsThePopupForTheDefaultPassword(): void
    {
        // Session ouverte sans mot de passe en clair (cookie « rester connecté ») : le garde de Laravel émet Login.
        $user = (new \App\Models\User())->newFromBuilder($this->row);
        auth()->guard()->login($user);

        [, $banner, $popup] = $this->page('/profile');
        $this->assertTrue($banner);
        $this->assertTrue($popup);
    }

    public function testStrongPasswordGetsNeitherBannerNorPopup(): void
    {
        $strong = bin2hex(random_bytes(6));
        $this->row['password_hash'] = $this->hash($strong);
        $this->row['must_change_password'] = 0;
        kintai_test_users(findById: fn() => $this->row);
        $this->auth->attempt('emp@example.test', $strong);

        [$response, $banner, $popup] = $this->page('/employee');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($banner);
        $this->assertFalse($popup);
    }

    public function testAnonymousVisitorsAreLeftAlone(): void
    {
        [$response, $banner, $popup] = $this->page('/login');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($banner);
        $this->assertFalse($popup);
    }
}
