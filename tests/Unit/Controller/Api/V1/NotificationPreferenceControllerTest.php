<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Auth\UserTargetGuard;
use kintai\Core\BundleManager;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\DatabaseNotificationPreferenceRepository;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationTypeRegistry;
use kintai\Domain\Eloquent\NotificationPreference;
use kintai\Tests\Support\ApiOwnerAuth;
use kintai\UI\Controller\Api\V1\NotificationPreferenceController;
use PHPUnit\Framework\TestCase;

/** GET|PUT /api/v1/users/{user_id}/notification-preferences, contre un vrai schéma SQLite. */
final class NotificationPreferenceControllerTest extends TestCase
{
    private NotificationPreferenceController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];

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

        $this->usersById = [20 => ['id' => 20, 'quiet_hours_start' => null, 'quiet_hours_end' => null]];
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => $this->usersById[$id] ?? null);
        $users->method('save')->willReturnCallback(function (array $data) {
            $this->usersById[(int) $data['id']] = $data;
            return $data;
        });

        $manager  = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $registry = new NotificationTypeRegistry(new ExtensionRegistry($manager));

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByUser')->willReturn([]);

        $this->controller = new NotificationPreferenceController(
            new NotificationPreferenceService(new DatabaseNotificationPreferenceRepository(), $registry, $users),
            new UserTargetGuard($users, $storeUsers, ApiOwnerAuth::permissions($this)),
        );
    }

    private function request(array $json = [], string $userId = '20'): Request
    {
        $req = ApiOwnerAuth::owner(new Request());
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setValue($req, $json);
        $req->setRouteParams(['user_id' => $userId]);
        return $req;
    }

    private function body(\kintai\Core\Response $response): array
    {
        return json_decode($response->body(), true);
    }

    public function testShowReturnsGridWithDefaultsAndQuietHours(): void
    {
        $response = $this->controller->show($this->request());

        $this->assertSame(200, $response->status());
        $data = $this->body($response);
        $this->assertSame(20, $data['user_id']);
        $this->assertSame(
            [['type' => 'shift_assigned', 'category' => 'planning', 'push' => true, 'email' => true],
             ['type' => 'shift_updated', 'category' => 'planning', 'push' => true, 'email' => false],
             ['type' => 'shift_deleted', 'category' => 'planning', 'push' => true, 'email' => true],
             ['type' => 'calendar_sync_error', 'category' => 'planning', 'push' => true, 'email' => false]],
            $data['preferences'],
        );
        $this->assertSame(['enabled' => false, 'start' => '22:00', 'end' => '07:00'], $data['quiet_hours']);
    }

    public function testUpdateChangesNamedBoxesAndReturnsTheNewState(): void
    {
        $response = $this->controller->update($this->request(['preferences' => ['shift_updated' => ['push' => false, 'email' => true]]]));

        $this->assertSame(200, $response->status());
        $byType = array_column($this->body($response)['preferences'], null, 'type');
        $this->assertFalse($byType['shift_updated']['push']);
        $this->assertTrue($byType['shift_updated']['email']);
        $this->assertTrue($byType['shift_assigned']['push'], 'case non citée : inchangée');
        $this->assertSame(2, NotificationPreference::count());
    }

    public function testUpdateSavesQuietHours(): void
    {
        $data = $this->body($this->controller->update($this->request(['quiet_hours' => ['enabled' => true, 'start' => '23:00', 'end' => '06:30']])));

        $this->assertSame(['enabled' => true, 'start' => '23:00', 'end' => '06:30'], $data['quiet_hours']);
        $this->assertSame('23:00', $this->usersById[20]['quiet_hours_start']);
    }

    public function testUpdateRejectsUnknownTypeAndSavesNothing(): void
    {
        try {
            $this->controller->update($this->request(['preferences' => [
                'shift_updated' => ['push' => false],
                'inconnu'       => ['push' => false],
            ]]));
            $this->fail('ValidationException attendue');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preferences', $e->errors);
        }
        $this->assertSame(0, NotificationPreference::count());
    }

    public function testUpdateRejectsInvalidQuietHours(): void
    {
        foreach ([
            ['enabled' => true, 'start' => '25:00', 'end' => '06:00'],
            ['enabled' => true, 'start' => '08:00', 'end' => '08:00'],
            ['enabled' => true],
            ['start' => '22:00', 'end' => '07:00'],
            'texte',
        ] as $quiet) {
            try {
                $this->controller->update($this->request(['quiet_hours' => $quiet]));
                $this->fail('ValidationException attendue pour ' . json_encode($quiet));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quiet_hours', $e->errors);
            }
        }
        $this->assertNull($this->usersById[20]['quiet_hours_start']);
    }

    public function testUpdateRejectsNonArrayPreferences(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->update($this->request(['preferences' => 'tout']));
    }
}
