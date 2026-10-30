<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api\V1;

use App\Auth\UserTargetGuard;
use App\Exceptions\ValidationException;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserDashboardPref;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Tests\Support\ApiOwnerAuth;
use App\Http\Controllers\Api\V1\UserPrefsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/** Préférences d'affichage par l'API : listes de chaînes et type de tableau de bord contrôlés (audit M9). */
final class UserPrefsControllerTest extends TestCase
{
    use \Tests\Support\PrefsExpectations;

    private ?UserPrefsController $controller = null;

    protected function setUp(): void
    {
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
                new UserTargetGuard($users, $storeUsers, ApiOwnerAuth::permissions($this)),
            );
        }

        return $this->controller;
    }

    private function request(array $body): Request
    {
        $req = ApiOwnerAuth::owner(Request::capture());
        $req->setRouteParams(['user_id' => '20']);
        $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
        return $req;
    }

    public function testDashboardWidgetsAreSaved(): void
    {
        $this->expectWidgets(20, ['stats', 'shifts'], 'employee');

        $response = $this->controller()->saveDashboardPrefs($this->request(['dashboard_type' => 'employee', 'widgets' => ['stats', 'shifts']]));

        $this->assertSame(200, $response->getStatusCode());
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
        $this->expectNoPrefsWrite();

        $this->expectException(ValidationException::class);
        $this->controller()->saveDashboardPrefs($this->request($body));
    }

    public function testLayoutIsSavedWithoutTouchingTheEnabledWidgets(): void
    {
        $this->expectNoPrefsWrite();
        $this->expectLayout(20, 'admin', ['order' => ['shifts_today', 'kpi_counters'], 'widths' => ['shifts_today' => 'half']]);

        $response = $this->controller()->saveDashboardPrefs($this->request([
            'dashboard_type' => 'admin',
            'layout'         => ['order' => ['shifts_today', 'kpi_counters', 'Bad Key'], 'widths' => ['shifts_today' => 'half', 'kpi_counters' => 'huge']],
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testANullLayoutResetsIt(): void
    {
        $this->expectLayout(20, 'employee', null);

        $this->controller()->saveDashboardPrefs($this->request(['dashboard_type' => 'employee', 'layout' => null]));
    }

    public function testALayoutThatIsNotAnObjectIsRefused(): void
    {
        $this->expectNoPrefsWrite();

        $this->expectException(ValidationException::class);
        $this->controller()->saveDashboardPrefs($this->request(['layout' => ['kpi_counters']]));
    }

    public function testGetReturnsTheLayout(): void
    {
        $this->seedLayout(20, 'admin', ['order' => ['kpi_counters'], 'widths' => []]);

        $req = $this->request([]);
        $response = $this->controller()->getDashboardPrefs($req);

        $this->assertSame(['order' => ['kpi_counters'], 'widths' => []], json_decode($response->getContent(), true)['layout']);
    }

    public function testNavPrefsMustBeAListOfStrings(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->saveNavPrefs($this->request(['hidden' => ['a' => 'b']]));
    }
}
