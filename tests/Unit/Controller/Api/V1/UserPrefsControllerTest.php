<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserDashboardPrefsRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Tests\Support\ApiOwnerAuth;
use kintai\UI\Controller\Api\V1\UserPrefsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/** Préférences d'affichage par l'API : listes de chaînes et type de tableau de bord contrôlés (audit M9). */
final class UserPrefsControllerTest extends TestCase
{
    private UserDashboardPrefsRepositoryInterface&Stub $dashboard;
    private UserNavPrefsRepositoryInterface&Stub $nav;
    private ?UserPrefsController $controller = null;

    protected function setUp(): void
    {
        $this->dashboard = $this->createStub(UserDashboardPrefsRepositoryInterface::class);
        $this->nav       = $this->createStub(UserNavPrefsRepositoryInterface::class);
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): UserPrefsController
    {
        if ($this->controller === null) {
            $users = $this->createStub(UserRepositoryInterface::class);
            $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
            $users->method('findById')->willReturn(['id' => 20]);
            $storeUsers->method('findByUser')->willReturn([]);
            $this->controller = new UserPrefsController(
                $this->dashboard,
                $this->nav,
                new UserTargetGuard($users, $storeUsers, ApiOwnerAuth::permissions($this)),
            );
        }

        return $this->controller;
    }

    private function request(array $body): Request
    {
        $req = ApiOwnerAuth::owner(new Request());
        $req->setRouteParams(['user_id' => '20']);
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setValue($req, $body);
        return $req;
    }

    public function testDashboardWidgetsAreSaved(): void
    {
        $this->dashboard = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->dashboard->expects($this->once())->method('saveWidgets')->with(20, ['stats', 'shifts'], 'employee');

        $response = $this->controller()->saveDashboardPrefs($this->request(['dashboard_type' => 'employee', 'widgets' => ['stats', 'shifts']]));

        $this->assertSame(200, $response->status());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDashboardPrefs(): array
    {
        return [
            'type inconnu'          => [['dashboard_type' => 'root', 'widgets' => []]],
            'widgets en texte'      => [['widgets' => 'stats']],
            'widgets non textuels'  => [['widgets' => [['nested' => true]]]],
        ];
    }

    #[DataProvider('invalidDashboardPrefs')]
    public function testInvalidDashboardPrefsAreRefused(array $body): void
    {
        $this->dashboard = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->dashboard->expects($this->never())->method('saveWidgets');

        $this->expectException(ValidationException::class);
        $this->controller()->saveDashboardPrefs($this->request($body));
    }

    public function testLayoutIsSavedWithoutTouchingTheEnabledWidgets(): void
    {
        $this->dashboard = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->dashboard->expects($this->never())->method('saveWidgets');
        $this->dashboard->expects($this->once())->method('saveLayout')
            ->with(20, 'admin', ['order' => ['shifts_today', 'kpi_counters'], 'widths' => ['shifts_today' => 'half']]);

        $response = $this->controller()->saveDashboardPrefs($this->request([
            'dashboard_type' => 'admin',
            'layout'         => ['order' => ['shifts_today', 'kpi_counters', 'Bad Key'], 'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'huge']],
        ]));

        $this->assertSame(200, $response->status());
    }

    public function testANullLayoutResetsIt(): void
    {
        $this->dashboard = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->dashboard->expects($this->once())->method('saveLayout')->with(20, 'employee', null);

        $this->controller()->saveDashboardPrefs($this->request(['dashboard_type' => 'employee', 'layout' => null]));
    }

    public function testALayoutThatIsNotAnObjectIsRefused(): void
    {
        $this->dashboard = $this->createMock(UserDashboardPrefsRepositoryInterface::class);
        $this->dashboard->expects($this->never())->method('saveLayout');

        $this->expectException(ValidationException::class);
        $this->controller()->saveDashboardPrefs($this->request(['layout' => ['kpi_counters']]));
    }

    public function testGetReturnsTheLayout(): void
    {
        $this->dashboard->method('getLayout')->willReturn(['order' => ['kpi_counters'], 'widths' => []]);

        $req = $this->request([]);
        $response = $this->controller()->getDashboardPrefs($req);

        $this->assertSame(['order' => ['kpi_counters'], 'widths' => []], json_decode($response->body(), true)['layout']);
    }

    public function testNavPrefsMustBeAListOfStrings(): void
    {
        $this->nav = $this->createMock(UserNavPrefsRepositoryInterface::class);
        $this->nav->expects($this->never())->method('saveHidden');

        $this->expectException(ValidationException::class);
        $this->controller()->saveNavPrefs($this->request(['hidden' => ['a' => 'b']]));
    }
}
