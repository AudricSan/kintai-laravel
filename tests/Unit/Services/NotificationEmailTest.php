<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Jobs\DeliverNotification;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use App\Mail\MailerService;
use App\Models\DevicePushToken;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Repositories\UserRepositoryInterface;
use App\Services\AppSettingsService;
use App\Services\NotificationMailer;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\NotificationTypeRegistry;
use App\Services\PublicUrlResolver;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Services\UnsubscribeTokens;
use App\Models\Notification;
use Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Canal e-mail des notifications de bout en bout : vraie base SQLite, vrais textes de lang/, seul le transport d'envoi
 * est remplacé par un enregistreur.
 */
final class NotificationEmailTest extends TestCase
{
    /** @var list<array{to: array, subject: string, html: string, headers: array}> */
    private array $sent = [];
    private bool $transportSucceeds = true;
    private InMemoryAppSettings $settings;
    private NotificationPreferenceService $prefs;
    private UnsubscribeTokens $tokens;
    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];

    protected function setUp(): void
    {
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

        $this->usersById = [
            7 => ['id' => 7, 'email' => 'employe@exemple.fr', 'language' => 'fr', 'quiet_hours_start' => null, 'quiet_hours_end' => null],
            8 => ['id' => 8, 'email' => 'employee@example.org', 'language' => 'en', 'quiet_hours_start' => null, 'quiet_hours_end' => null],
            9 => ['id' => 9, 'email' => '', 'language' => 'fr', 'quiet_hours_start' => null, 'quiet_hours_end' => null],
        ];
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => $this->usersById[$id] ?? null);
        $users->method('save')->willReturnCallback(function (array $data) {
            $this->usersById[(int) $data['id']] = $data;
            return $data;
        });

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $registry = new NotificationTypeRegistry(new ExtensionRegistry($manager));
        $this->prefs = new NotificationPreferenceService($registry, $users);

        $this->settings = new InMemoryAppSettings(['app_public_url' => 'https://kintai.exemple.fr/app']);
        $this->tokens   = new UnsubscribeTokens($this->settings);
    }

    private function service(bool $configured = true, bool $withMailer = true, ?Dispatcher $bus = null): NotificationService
    {
        $transport = kintai_test_mailer(fn(array $to, string $subject, string $htmlBody, array $extraHeaders): bool => $this->record($to, $subject, $htmlBody, $extraHeaders));
        $config = $configured ? ['default' => 'sendmail', 'from' => ['address' => 'kintai@mon-domaine.fr', 'name' => 'Kintai']] : [];
        $translations = kintai_test_real_translator();
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => $this->usersById[$id] ?? null);

        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $mailer = new NotificationMailer(
            new MailerService($config, $transport),
            new PublicUrlResolver(new AppSettingsService($this->settings), ''),
            $this->tokens,
            $translations,
        );

        return new NotificationService(
            new PushNotificationService([]),
            $translations,
            $users,
            new NotificationTypeRegistry(new ExtensionRegistry($manager)),
            $this->prefs,
            null,
            $withMailer ? $mailer : null,
            $bus,
        );
    }

    /** @internal appelé par le faux transport */
    public function record(array $to, string $subject, string $html, array $headers): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'headers' => $headers];
        return $this->transportSucceeds;
    }

    public function testADecisionTypeSendsAnEmailWithButtonAndUnsubscribeLink(): void
    {
        $this->service()->notify(7, 'shift_assigned', 'notif_shift_assigned_body', [], 12, '/employee/shifts/day');

        $this->assertCount(1, $this->sent);
        $mail = $this->sent[0];
        $this->assertSame(['employe@exemple.fr'], $mail['to']);
        $this->assertSame('[Kintai] Nouveau shift assigné', $mail['subject']);
        $id = Notification::first()->id;
        $this->assertStringContainsString('href="https://kintai.exemple.fr/app/notifications/' . $id . '/open"', $mail['html']);
        $this->assertStringContainsString('Voir', $mail['html']);

        $sig = $this->tokens->sign(7, 'shift_assigned', 'email');
        $url = 'https://kintai.exemple.fr/app/notifications/unsubscribe?u=7&t=shift_assigned&c=email&s=' . $sig;
        $this->assertStringContainsString('href="' . htmlspecialchars($url, ENT_QUOTES) . '"', $mail['html']);
        $this->assertSame('<' . $url . '>', $mail['headers']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail['headers']['List-Unsubscribe-Post']);
    }

    public function testWithAQueueTheEmailLeavesWithTheJobNotDuringTheRequest(): void
    {
        $jobs = [];
        $bus  = $this->createStub(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $job) use (&$jobs): mixed {
            $jobs[] = $job;
            return null;
        });
        $service = $this->service(bus: $bus);

        $service->notify(7, 'shift_assigned', 'notif_shift_assigned_body', [], 12, '/employee/shifts/day');

        // La cloche est écrite tout de suite ; l'e-mail attend la tâche.
        $this->assertSame(1, Notification::count());
        $this->assertSame([], $this->sent);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertInstanceOf(DeliverNotification::class, $job);
        $this->assertSame(7, $job->userId);
        $this->assertSame(Notification::first()->id, $job->notificationId);
        $this->assertSame('/employee/shifts/day', $job->link);
        $this->assertTrue($job->email);

        // La tâche, une fois traitée (après la réponse, ou par queue:work), envoie le même e-mail qu'avant la file.
        $service->deliver($job);
        $this->assertCount(1, $this->sent);
        $this->assertSame('[Kintai] Nouveau shift assigné', $this->sent[0]['subject']);

        // Une tâche ne voyage qu'avec des valeurs simples : elle passe par une file « database » sans perte.
        $this->assertEquals($job, unserialize(serialize($job)));
    }

    public function testAQueueThatFailsToAcceptTheJobDoesNotLoseTheEmail(): void
    {
        $bus = $this->createStub(Dispatcher::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('table jobs absente'));

        $this->service(bus: $bus)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertCount(1, $this->sent);
    }

    public function testNothingIsQueuedWhenTheUserTurnedEveryExternalChannelOff(): void
    {
        $this->prefs->saveChanges(7, ['shift_assigned' => ['push' => false, 'email' => false]]);
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->never())->method('dispatch');

        $this->service(bus: $bus)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame(1, Notification::count());
    }

    public function testTheEmailIsWrittenInTheRecipientsLanguage(): void
    {
        $this->service()->notify(8, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame('[Kintai] New shift assigned', $this->sent[0]['subject']);
        $this->assertStringContainsString('lang="en"', $this->sent[0]['html']);
        $this->assertStringContainsString('View', $this->sent[0]['html']);
        $this->assertStringContainsString('Stop receiving this type of email', $this->sent[0]['html']);
    }

    public function testNoEmailByDefaultForATypeThatIsNotADecision(): void
    {
        $this->service()->notify(7, 'shift_updated', 'notif_shift_updated_body');

        $this->assertSame([], $this->sent);
        $this->assertSame(1, Notification::count(), 'la cloche reçoit toujours la notification');
    }

    public function testTheUserCanTurnTheEmailOnOrOffPerType(): void
    {
        $this->prefs->saveChanges(7, ['shift_updated' => ['email' => true], 'shift_assigned' => ['email' => false]]);
        $service = $this->service();

        $service->notify(7, 'shift_updated', 'notif_shift_updated_body');
        $service->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertCount(1, $this->sent);
        $this->assertSame('[Kintai] Shift modifié', $this->sent[0]['subject']);
    }

    /** Un e-mail ne réveille personne : les heures silencieuses ne le retiennent pas. */
    public function testEmailIsStillSentDuringQuietHours(): void
    {
        $now = new \DateTimeImmutable();
        $this->assertTrue($this->prefs->saveQuietHours(7, true, $now->modify('-1 hour')->format('H:i'), $now->modify('+1 hour')->format('H:i')));
        $this->assertTrue($this->prefs->isQuietNow(7), 'précondition');

        $this->service()->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertCount(1, $this->sent);
        $this->assertSame(1, Notification::count());
    }

    public function testNothingIsSentWhenMailIsNotConfiguredOrNoPublicUrlOrNoAddress(): void
    {
        $this->service(configured: false)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');
        $this->assertSame([], $this->sent, 'e-mail non réglé sur l\'instance');

        $this->settings->values = [];
        $this->service()->notify(7, 'shift_assigned', 'notif_shift_assigned_body');
        $this->assertSame([], $this->sent, 'sans URL publique les liens ne seraient pas absolus');

        $this->settings->values = ['app_public_url' => 'https://kintai.exemple.fr'];
        $this->service()->notify(9, 'shift_assigned', 'notif_shift_assigned_body');
        $this->assertSame([], $this->sent, 'utilisateur sans adresse e-mail');

        $this->assertSame(3, Notification::count(), 'les notifications sont toutes créées');
    }

    public function testAFailingTransportNeverBreaksTheNotification(): void
    {
        $this->transportSucceeds = false;

        $this->service()->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertCount(1, $this->sent);
        $this->assertSame(1, Notification::count());
    }

    public function testNotifyManyEmailsEachRecipientWithTheirOwnSettings(): void
    {
        $this->prefs->saveChanges(8, ['shift_assigned' => ['email' => false]]);

        $this->service()->notifyMany([7, 8], 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame([['employe@exemple.fr']], array_column($this->sent, 'to'));
        $this->assertSame(2, Notification::count());
    }

    public function testWithoutMailerThereIsNoEmail(): void
    {
        $this->service(withMailer: false)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame([], $this->sent);
    }
}
