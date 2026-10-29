<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Web;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Auth\AuthService;
use kintai\Core\BundleManager;
use Illuminate\Container\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Mail\MailerService;
use kintai\Core\Mail\MailTransportInterface;
use kintai\Core\Repositories\DatabaseNotificationPreferenceRepository;
use kintai\Core\Repositories\DatabaseNotificationRepository;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Repositories\JsonLanguageRepository;
use kintai\Core\Repositories\JsonTranslationRepository;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use Illuminate\Http\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationChannelStatus;
use kintai\Core\Services\NotificationMailer;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\NotificationTypeRegistry;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use kintai\Core\Services\UnsubscribeTokens;
use kintai\Core\Services\VapidKeyStore;
use kintai\Domain\Eloquent\Notification;
use kintai\Tests\Support\InMemoryAppSettings;
use kintai\UI\Controller\Web\System\NotificationSettingsController;
use kintai\UI\ViewRenderer;
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
    private ViewRenderer $view;
    /** @var list<array> e-mails envoyés */
    public array $mails = [];
    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];

    protected function setUp(): void
    {
        session()->put('auth_user_id', 1);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('notifications', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type');
            $table->string('body_key')->nullable();
            $table->text('params')->nullable();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        $schema->create('notification_preferences', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->unique(['user_id', 'type', 'channel']);
        });

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
            $this->createStub(RememberTokenRepositoryInterface::class),
        );

        $this->settings = new InMemoryAppSettings(['app_public_url' => 'https://kintai.exemple.fr', 'notifications_retention_days' => '45']);
        $appSettings    = new AppSettingsService($this->settings);
        $vapid          = new VapidKeyStore($this->settings);
        $vapid->ensure();

        $subscriptions = $this->createStub(WebPushSubscriptionRepositoryInterface::class);
        $subscriptions->method('countAll')->willReturn(3);
        $subscriptions->method('findByUser')->willReturn([['id' => 1]]);
        $devices = $this->createStub(DevicePushTokenRepositoryInterface::class);

        $transport = new class($this) implements MailTransportInterface {
            public function __construct(private NotificationSettingsControllerTest $test) {}

            public function send(string $fromAddress, string $fromName, array $to, string $subject, string $htmlBody, array $attachmentPaths = [], array $extraHeaders = []): bool
            {
                $this->test->mails[] = ['to' => $to, 'subject' => $subject];
                return true;
            }
        };
        $mailer = new MailerService(['driver' => 'native', 'from' => ['address' => 'kintai@mon-domaine.fr']], $transport);
        $publicUrl = new PublicUrlResolver($appSettings, '');

        $translations = kintai_test_real_translator('en', false);
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $registry = new NotificationTypeRegistry(new ExtensionRegistry($manager));
        $this->prefs = new NotificationPreferenceService(new DatabaseNotificationPreferenceRepository(), $registry, $users);

        $notifications = new NotificationService(
            new DatabaseNotificationRepository(),
            new PushNotificationService([], $devices),
            $translations,
            $users,
            $registry,
            $this->prefs,
            null,
            new NotificationMailer($mailer, $publicUrl, new UnsubscribeTokens($this->settings), $translations),
        );

        $this->view = new ViewRenderer(BASE_PATH . '/app/UI/View');
        $this->view->share('BASE_URL', '');
        $this->status = new NotificationChannelStatus($vapid, $subscriptions, $devices, $mailer, $publicUrl, $users, []);
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
        session()->forget(['auth_user_id', '_token']);
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

            $this->assertStringContainsString('success=saved', $this->location($response));
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
        $this->assertStringContainsString('success=test', $location);
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

        $this->assertStringContainsString('success=vapid', $this->location($response));
        $this->assertNotSame($before, $this->settings->get(VapidKeyStore::PUBLIC_KEY));
    }

    /** La page sans la mise en page de l'application (qui demande toutes les routes du site). */
    private function page(array $testChannels): string
    {
        return $this->view->render('system.notification-settings', [
            'retention_days' => 45, 'retention_min' => 7, 'retention_max' => 3650,
            'status' => $this->status->all(), 'test_channels' => $testChannels,
        ]);
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
        $_GET = ['success' => 'test'];

        $html = $this->page(array_values(array_intersect(explode(',', 'bell,<script>,email'), ['bell', 'web_push', 'mobile', 'email'])));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('notif_admin_test_sent', $html);
    }
}
