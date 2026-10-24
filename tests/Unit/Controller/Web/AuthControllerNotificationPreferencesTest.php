<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Auth\AuthService;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\DatabaseNotificationPreferenceRepository;
use kintai\Core\Repositories\IcalTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserNavPrefsRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\AvatarImageOptimizer;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationTypeRegistry;
use kintai\Domain\Eloquent\NotificationPreference;
use kintai\UI\Controller\Web\AuthController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

/** Onglet Notifications du profil : enregistrement de la grille et des heures silencieuses (POST /profile/notifications). */
final class AuthControllerNotificationPreferencesTest extends TestCase
{
    private AuthController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_user_id'] = 9;

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

        $this->usersById = [9 => ['id' => 9, 'quiet_hours_start' => null, 'quiet_hours_end' => null, 'is_active' => 1, 'deleted_at' => null]];
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => $this->usersById[$id] ?? null);
        $users->method('save')->willReturnCallback(function (array $data) {
            $this->usersById[(int) $data['id']] = $data;
            return $data;
        });

        $storeUsers      = $this->createStub(StoreUserRepositoryInterface::class);
        $stores          = $this->createStub(StoreRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $auth = new AuthService(
            $users,
            $storeUsers,
            $stores,
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $prefs = new NotificationPreferenceService(
            new DatabaseNotificationPreferenceRepository(),
            new NotificationTypeRegistry(new ExtensionRegistry($manager)),
            $users,
        );

        $this->controller = new AuthController(
            new ViewRenderer(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $stores,
            $storeUsers,
            $this->createStub(IcalTokenRepositoryInterface::class),
            $this->createStub(UserNavPrefsRepositoryInterface::class),
            $this->createStub(AvailabilityRepositoryInterface::class),
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            null,
            null,
            $prefs,
        );
    }

    protected function tearDown(): void
    {
        unset($_SESSION['auth_user_id']);
        $_POST = [];
    }

    private function location(\kintai\Core\Response $response): string
    {
        $headers = new \ReflectionProperty($response, 'headers');
        return (string) ($headers->getValue($response)['Location'] ?? '');
    }

    public function testSavesCheckedBoxesAsDeviationsAndQuietHours(): void
    {
        // shift_updated : push décoché (défaut oui), e-mail coché (défaut non). Les autres types restent par défaut.
        $_POST = [
            'push'          => ['shift_assigned', 'shift_deleted', 'calendar_sync_error'],
            'email'         => ['shift_assigned', 'shift_updated', 'shift_deleted'],
            'quiet_enabled' => '1',
            'quiet_start'   => '22:00',
            'quiet_end'     => '07:00',
        ];

        $response = $this->controller->saveNotificationPreferences(new Request());

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('tab=notifications', $this->location($response));
        $this->assertStringContainsString('success=saved', $this->location($response));
        $rows = NotificationPreference::orderBy('channel')->get(['type', 'channel', 'enabled'])->toArray();
        $this->assertSame([
            ['type' => 'shift_updated', 'channel' => 'email', 'enabled' => true],
            ['type' => 'shift_updated', 'channel' => 'push', 'enabled' => false],
        ], $rows);
        $this->assertSame('22:00', $this->usersById[9]['quiet_hours_start']);
        $this->assertSame('07:00', $this->usersById[9]['quiet_hours_end']);
    }

    /** shown[] limite l'enregistrement aux lignes que le formulaire affichait : un type apparu depuis n'est pas coupé. */
    public function testOnlyTheRowsTheFormShowedAreSaved(): void
    {
        $_POST = [
            'push'  => ['shift_assigned'],
            'email' => ['shift_assigned'],
            'shown' => ['shift_assigned', 'shift_deleted'],
        ];

        $this->controller->saveNotificationPreferences(new Request());

        // shift_deleted (affiché, décoché) : push et e-mail coupés ; shift_updated (non affiché) : inchangé.
        $this->assertSame(
            [['type' => 'shift_deleted', 'channel' => 'email', 'enabled' => false], ['type' => 'shift_deleted', 'channel' => 'push', 'enabled' => false]],
            NotificationPreference::orderBy('channel')->get(['type', 'channel', 'enabled'])->toArray(),
        );
    }

    public function testEverythingUncheckedTurnsExternalChannelsOffAndQuietHoursOff(): void
    {
        $_POST = ['quiet_start' => '22:00', 'quiet_end' => '07:00'];

        $this->controller->saveNotificationPreferences(new Request());

        // 4 types du Core, tout décoché : écart partout où le défaut était « activé » (4 push + 2 e-mails).
        $this->assertSame(6,NotificationPreference::where('enabled', false)->count());
        $this->assertNull($this->usersById[9]['quiet_hours_start']);
    }

    public function testInvalidQuietHoursRedirectWithErrorAndSaveNothing(): void
    {
        $_POST = ['push' => [], 'quiet_enabled' => '1', 'quiet_start' => '10:00', 'quiet_end' => '10:00'];

        $response = $this->controller->saveNotificationPreferences(new Request());

        $this->assertStringContainsString('error=quiet_hours_invalid', $this->location($response));
        $this->assertSame(0, NotificationPreference::count());
    }

    public function testIgnoresNonStringEntriesInPostedLists(): void
    {
        $_POST = ['push' => [['x'], 'shift_assigned'], 'email' => 'texte'];

        $response = $this->controller->saveNotificationPreferences(new Request());

        $this->assertSame(302, $response->status());
    }
}
