<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use Illuminate\Container\Container;
use App\Repositories\AvailabilityRepositoryInterface;
use App\Repositories\IcalTokenRepositoryInterface;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserNavPrefsRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Http\Controllers\AuthController;
use App\View\ViewRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 01/10/2026 (point 4) : le changement de langue et de vue mobile/bureau renvoyait vers le Referer brut,
 * envoyé par le navigateur, donc éventuellement vers un autre site. Seule une page du site est désormais acceptée.
 */
final class AuthControllerSwitchRefererTest extends TestCase
{
    private AuthController $controller;
    private ?Container $previous = null;

    protected function setUp(): void
    {
        $this->previous = self::swapContainer(new \Tests\Support\TestContainer());

        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'en'], ['code' => 'ja']]);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $users = $this->createStub(UserRepositoryInterface::class);
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $auth = new AuthService(
            $users, $storeUsers, $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class), $roleAssignments,
            $this->createStub(UserRepositoryInterface::class),
        );

        $this->controller = new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $this->createStub(StoreRepositoryInterface::class),
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $languages,
            new AvatarImageOptimizer(),
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_POST = [];
        session()->forget(['locale', 'device_view']);
        self::swapContainer($this->previous);
    }

    /** Remplace le conteneur global (lu par back_url()) et renvoie le précédent. */
    private static function swapContainer(?Container $container): ?Container
    {
        $prop     = new \ReflectionProperty(Container::class, 'instance');
        $previous = $prop->getValue();
        $prop->setValue(null, $container);

        return $previous;
    }

    private function request(string $method, string $uri, ?string $referer): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'kintai.test'];
        if ($referer !== null) {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
        $_GET = $_COOKIE = $_FILES = [];
        $request = Request::capture();
        // back_url() lit la requête courante dans le conteneur.
        app()->instance('request', $request);

        return $request;
    }

    private function location(Response $response): string
    {
        return (string) ($response->headers->get('Location', ''));
    }

    /** @return array<string, array{?string, string}> */
    public static function referers(): array
    {
        return [
            'page du site'   => ['http://kintai.test/admin/shifts?week=40', 'http://kintai.test/admin/shifts?week=40'],
            'site externe'   => ['https://evil.example/page', '/'],
            'même nom, autre hôte' => ['http://kintai.test.evil.example/', '/'],
            'absent'         => [null, '/'],
        ];
    }

    #[DataProvider('referers')]
    public function testLanguageSwitchOnlyReturnsToAPageOfTheSite(?string $referer, string $expected): void
    {
        $request = $this->request('POST', '/lang/ja', $referer);
        $request->setRouteParams(['locale' => 'ja']);

        $this->assertSame($expected, $this->location($this->controller->switchLanguage($request)));
    }

    #[DataProvider('referers')]
    public function testDeviceSwitchOnlyReturnsToAPageOfTheSite(?string $referer, string $expected): void
    {
        $_POST = ['device_view' => 'mobile'];

        $this->assertSame($expected, $this->location($this->controller->switchDevice($this->request('POST', '/switch-device', $referer))));
    }

    public function testUnknownLanguageAlsoUsesTheSafeReturn(): void
    {
        $request = $this->request('POST', '/lang/xx', 'https://evil.example/');
        $request->setRouteParams(['locale' => 'xx']);

        $this->assertSame('/', $this->location($this->controller->switchLanguage($request)));
    }
}
