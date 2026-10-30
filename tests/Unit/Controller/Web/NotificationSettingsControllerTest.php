<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use App\Auth\AuthService;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Extension\ExtensionRegistry;
use App\Mail\MailerService;
use App\Models\DevicePushToken;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\NotificationChannelStatus;
use App\Services\NotificationMailer;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\NotificationTypeRegistry;
use App\Services\PublicUrlResolver;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Services\UnsubscribeTokens;
use App\Services\VapidKeyStore;
use App\Models\Notification;
use Tests\Support\InMemoryAppSettings;
use App\Http\Controllers\System\NotificationSettingsController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 4));
}

/** Paramètres des notifications de l'instance (Owner) : conservation, notification de test, clés du web push. */
final class NotificationSettingsControllerTest extends TestCase
{
    private InMemoryAppSettings $settings;
    private NotificationSettingsController $controller;
    private NotificationPreferenceService $prefs;
    private NotificationChannelStatus $status;
    private ViewFactory $view;
    /** @var list<array> e-mails envoyés */
    public array $mails = [];
    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];

    protected function setUp(): void
    {
        session()->put(auth()->getName(), 1);
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_user(1);
        // Le navigateur du Owner est abonné au web push : le canal figure dans le test.
        \App\Models\WebPushSubscription::put(1, 'https://fcm.googleapis.com/fcm/send/owner', 'k', 'a', null);

        session()->put('_token', 'x');
        $router = kintai_test_registrar();
        // Routes citées par la page et ses onglets.
        foreach (['x', 'admin.owner_settings', 'admin.backup', 'admin.update', 'admin.languages', 'admin.bundles', 'admin.roles', 'admin.license', 'admin.calendar_settings', 'admin.notifications_settings', 'admin.notifications_settings.save', 'admin.notifications_settings.test', 'admin.notifications_settings.vapid', 'home', 'auth.login'] as $i => $name) {
            $router->get('/p' . $i, ['X', 'y'], name: $name);
        }

        $this->usersById = [1 => ['id' => 1, 'email' => 'owner@exemple.fr', 'language' => 'fr', 'is_active' => 1, 'deleted_at' => null, 'quiet_hours_start' => null, 'quiet_hours_end' => null]];
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => $this->usersById[$id] ?? null);
        $users->method('save')->willReturnCallback(function (array $data) {
            $this->usersById[(int) $data['id']] = $data;
            return $data;
        });
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $auth = new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $roleAssignments,
            $this->createStub(UserRepositoryInterface::class),
        );

        $this->settings = new InMemoryAppSettings(['app_public_url' => 'https://kintai.exemple.fr', 'notifications_retention_days' => '45']);
        $appSettings    = new AppSettingsService();
        $vapid          = new VapidKeyStore();
        $vapid->ensure();

        $transport = kintai_test_mailer(function (array $to, string $subject): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject];
            return true;
        });
        $mailer = new MailerService(['default' => 'sendmail', 'from' => ['address' => 'kintai@mon-domaine.fr']], $transport);
        $publicUrl = new PublicUrlResolver($appSettings, '');

        $translations = kintai_test_real_translator('en', false);
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $registry = new NotificationTypeRegistry(new ExtensionRegistry($manager));
        $this->prefs = new NotificationPreferenceService($registry, $users);

        $notifications = new NotificationService(
            new PushNotificationService([]),
            $translations,
            $users,
            $registry,
            $this->prefs,
            null,
            new NotificationMailer($mailer, $publicUrl, new UnsubscribeTokens(), $translations),
        );

        $this->view = kintai_test_views(BASE_PATH . '/resources/views');
        $this->view->share('BASE_URL', '');
        $this->status = new NotificationChannelStatus($vapid, $mailer, $publicUrl, $users, []);
        $this->controller = new NotificationSettingsController(
            $this->view,
            $appSettings,
            $this->status,
            $vapid,
            $notifications,
            $auth,
            new AuditLogger(),
        );
    }

    protected function tearDown(): void
    {
        session()->forget([auth()->getName(), '_token']);
        $_POST = [];
        $_GET  = [];
    }

    private function location(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return (string) (kintai_test_headers($response)['Location'] ?? '');
    }

    public function testSavingTheRetentionClampsItToTheAllowedRange(): void
    {
        foreach ([['30', '30'], ['2', '7'], ['0', '7'], ['999999', '3650'], ['abc', '7']] as [$posted, $stored]) {
            $_POST = ['notifications_retention_days' => $posted];

            $response = $this->controller->save(Request::capture());

            $this->assertStringContainsString('saved', (string) session('success'));
            $this->assertSame($stored, $this->settings->get('notifications_retention_days'), "saisi : $posted");
        }
    }

    public function testRetentionIsKeptWhenTheFieldIsAbsent(): void
    {
        $_POST = [];

        $this->controller->save(Request::capture());

        $this->assertSame('45', $this->settings->get('notifications_retention_days'));
    }

    public function testTestNotificationIgnoresPreferencesAndQuietHoursAndReportsTheChannels(): void
    {
        // Tout est coupé pour ce compte : le test doit passer quand même.
        $now = new \DateTimeImmutable();
        $this->prefs->saveQuietHours(1, true, $now->modify('-1 hour')->format('H:i'), $now->modify('+1 hour')->format('H:i'));
        $this->prefs->saveChanges(1, [NotificationPreferenceService::TEST_TYPE => ['push' => false, 'email' => false]]);

        $response = $this->controller->sendTest(Request::capture());

        $this->assertSame(1, Notification::where('user_id', 1)->where('type', NotificationPreferenceService::TEST_TYPE)->count());
        $this->assertCount(1, $this->mails, 'l\'e-mail part malgré les préférences et les heures silencieuses');
        $this->assertSame(['owner@exemple.fr'], $this->mails[0]['to']);
        $location = $this->location($response);
        $this->assertStringContainsString('test', (string) session('success'));
        $this->assertStringContainsString('channels=bell,web_push,email', $location);
    }

    public function testTheTestTypeIsNotOfferedInThePreferencesGridNorTouchedBySavingIt(): void
    {
        $grid = $this->prefs->grid(1);
        $types = array_map(fn($row) => $row['type']->type, array_merge(...array_values($grid)));
        $this->assertNotContains(NotificationPreferenceService::TEST_TYPE, $types);

        $this->prefs->saveGrid(1, [], []);

        $this->assertTrue($this->prefs->isEnabled(1, NotificationPreferenceService::TEST_TYPE, 'push'), 'enregistrer la grille ne change pas le type de test');
    }

    public function testRegeneratingTheKeysReplacesThePair(): void
    {
        $before = $this->settings->get(VapidKeyStore::PUBLIC_KEY);

        $response = $this->controller->regenerateVapid(Request::capture());

        $this->assertStringContainsString('vapid', (string) session('success'));
        $this->assertNotSame($before, $this->settings->get(VapidKeyStore::PUBLIC_KEY));
    }

    /** La page sans la mise en page de l'application (qui demande toutes les routes du site). */
    private function page(array $testChannels): string
    {
        return $this->view->make('system.notification-settings', [
            'retention_days' => 45, 'retention_min' => 7, 'retention_max' => 3650,
            'status' => $this->status->all(), 'test_channels' => $testChannels,
        ])->render();
    }

    public function testThePageShowsTheStateOfEachChannel(): void
    {
        $html = $this->page([]);

        $this->assertStringContainsString('notif_admin_web_subscriptions', $html);
        $this->assertStringContainsString('notif_admin_email_driver', $html);
        $this->assertStringContainsString('notif_admin_mobile_disabled', $html);
        $this->assertStringContainsString('value="45"', $html);
        $this->assertStringContainsString('data-confirm=', $html);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit)=|\sstyle="/i', $html);
    }

    public function testTheChannelListOfTheLastTestIsLimitedToKnownChannels(): void
    {
        session()->put('success', 'test');

        $html = $this->page(array_values(array_intersect(explode(',', 'bell,<script>,email'), ['bell', 'web_push', 'mobile', 'email'])));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('notif_admin_test_sent', $html);
    }
}
