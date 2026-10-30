<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Auth\AuthService;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use App\Models\Availability;
use App\Repositories\DatabaseNotificationPreferenceRepository;
use App\Models\IcalToken;
use App\Repositories\LanguageRepositoryInterface;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserNavPref;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AuditLogger;
use App\Services\AvatarImageOptimizer;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationTypeRegistry;
use App\Models\NotificationPreference;
use App\Http\Controllers\AuthController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

/** Onglet Notifications du profil : enregistrement de la grille et des heures silencieuses (POST /profile/notifications). */
final class AuthControllerNotificationPreferencesTest extends TestCase
{
    private AuthController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];

    protected function setUp(): void
    {
        session()->put(auth()->getName(), 9);

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
            $this->createStub(UserRepositoryInterface::class),
        );

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $prefs = new NotificationPreferenceService(
            new NotificationTypeRegistry(new ExtensionRegistry($manager)),
            $users,
        );

        $this->controller = new AuthController(
            kintai_test_views(sys_get_temp_dir()),
            $auth,
            new AuditLogger(),
            $users,
            $stores,
            $storeUsers,
            $this->createStub(LanguageRepositoryInterface::class),
            new AvatarImageOptimizer(),
            null,
            null,
            $prefs,
        );
    }

    protected function tearDown(): void
    {
        session()->forget(auth()->getName());
        $_POST = [];
    }

    private function location(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return (string) (kintai_test_headers($response)['Location'] ?? '');
    }

    public function testSavesCheckedBoxesAsDeviationsAndQuietHours(): void
    {
        // shift_updated : push décoché (défaut oui), e-mail coché (défaut non). Les autres types restent par défaut.
        $_POST = [
            'push'          => ['shift_assigned', 'shift_deleted', 'schedule_published', 'calendar_sync_error'],
            'email'         => ['shift_assigned', 'shift_updated', 'shift_deleted', 'schedule_published'],
            'quiet_enabled' => '1',
            'quiet_start'   => '22:00',
            'quiet_end'     => '07:00',
        ];

        $response = $this->controller->saveNotificationPreferences(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
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

        $this->controller->saveNotificationPreferences(Request::capture());

        // shift_deleted (affiché, décoché) : push et e-mail coupés ; shift_updated (non affiché) : inchangé.
        $this->assertSame(
            [['type' => 'shift_deleted', 'channel' => 'email', 'enabled' => false], ['type' => 'shift_deleted', 'channel' => 'push', 'enabled' => false]],
            NotificationPreference::orderBy('channel')->get(['type', 'channel', 'enabled'])->toArray(),
        );
    }

    public function testEverythingUncheckedTurnsExternalChannelsOffAndQuietHoursOff(): void
    {
        $_POST = ['quiet_start' => '22:00', 'quiet_end' => '07:00'];

        $this->controller->saveNotificationPreferences(Request::capture());

        // 5 types du Core, tout décoché : écart partout où le défaut était « activé » (5 push + 3 e-mails).
        $this->assertSame(8,NotificationPreference::where('enabled', false)->count());
        $this->assertNull($this->usersById[9]['quiet_hours_start']);
    }

    public function testInvalidQuietHoursRedirectWithErrorAndSaveNothing(): void
    {
        $_POST = ['push' => [], 'quiet_enabled' => '1', 'quiet_start' => '10:00', 'quiet_end' => '10:00'];

        $response = $this->controller->saveNotificationPreferences(Request::capture());

        $this->assertStringContainsString('error=quiet_hours_invalid', $this->location($response));
        $this->assertSame(0, NotificationPreference::count());
    }

    public function testIgnoresNonStringEntriesInPostedLists(): void
    {
        $_POST = ['push' => [['x'], 'shift_assigned'], 'email' => 'texte'];

        $response = $this->controller->saveNotificationPreferences(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }
}
