<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Setup;

use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Mail\MailerService;
use kintai\Core\Repositories\DevicePushTokenRepositoryInterface;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\WebPushSubscriptionRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\EnvFileWriter;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\NotificationChannelStatus;
use kintai\Core\Services\NotificationPreferenceService;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\StoreServiceInterface;
use kintai\Core\Services\VapidKeyStore;
use kintai\Core\Setup\MailStep;
use kintai\Core\Setup\NotificationsStep;
use kintai\Core\Setup\OperationsStep;
use kintai\Core\Setup\OrganisationStep;
use kintai\Core\Setup\StoreStep;
use kintai\Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 4));
}

/** Étapes de l'assistant de configuration : validation, enregistrement et résumé de chacune. */
final class SetupStepsTest extends TestCase
{
    private InMemoryAppSettings $store;
    private AppSettingsService $settings;
    private string $envDir;

    protected function setUp(): void
    {
        $this->store    = new InMemoryAppSettings();
        $this->settings = new AppSettingsService($this->store);
        $this->envDir   = sys_get_temp_dir() . '/kintai-setup-env-' . bin2hex(random_bytes(4));
        mkdir($this->envDir);
        file_put_contents($this->envDir . '/.env', "APP_ENV=local\n");
    }

    protected function tearDown(): void
    {
        $_POST = [];
        foreach (['MAIL_DRIVER', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_ENCRYPTION', 'MAIL_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach (glob($this->envDir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->envDir);
    }

    private function post(array $post): Request
    {
        $_POST = $post;
        return new Request();
    }

    // ── Organisation ────────────────────────────────────────────────────────────────────────────────────────────

    private function organisation(): OrganisationStep
    {
        return new OrganisationStep($this->settings, new PublicUrlResolver($this->settings, ''));
    }

    public function testOrganisationSavesTheNameEmailAndPublicUrl(): void
    {
        $errors = $this->organisation()->handle($this->post(['name' => ' Ma Boutique ', 'support_email' => 'aide@exemple.fr', 'public_url' => 'https://kintai.exemple.fr/']), 1);

        $this->assertSame([], $errors);
        $this->assertSame('Ma Boutique', $this->settings->subtitle());
        $this->assertSame('aide@exemple.fr', $this->settings->supportEmail());
        $this->assertSame('https://kintai.exemple.fr', $this->settings->get(PublicUrlResolver::SETTING_KEY));
        $this->assertSame('Ma Boutique', $this->organisation()->summary());
    }

    public function testOrganisationRefusesAMissingNameABadEmailAndABadUrlWithoutSavingAnything(): void
    {
        $errors = $this->organisation()->handle($this->post(['name' => '', 'support_email' => 'pas-un-mail', 'public_url' => 'javascript:alert(1)']), 1);

        $this->assertSame(['setup_org_name_required', 'setup_org_email_invalid', 'public_url_invalid'], $errors);
        $this->assertSame('', $this->settings->subtitle());
        $this->assertNull($this->organisation()->summary());
    }

    // ── Exploitation ────────────────────────────────────────────────────────────────────────────────────────────

    public function testOperationsSavesBackupAndUpdateChannelForTheCoreAndTheBundles(): void
    {
        $step = new OperationsStep($this->settings);

        $this->assertSame([], $step->handle($this->post(['backup_auto' => '1', 'backup_keep' => '14', 'update_channel' => 'beta']), 1));

        $this->assertTrue($this->settings->backupAutoEnabled());
        $this->assertSame(14, $this->settings->backupMaxKeep());
        $this->assertSame('beta', $this->settings->updateChannel());
        $this->assertSame('beta', $this->settings->bundleUpdateChannel());
        $this->assertSame([], $step->handle($this->post(['update_channel' => 'release', 'backup_keep' => '0']), 1));
        $this->assertFalse($this->settings->backupAutoEnabled(), 'case décochée = sauvegarde automatique coupée');
    }

    public function testOperationsRefusesAnUnknownChannelOrAnAbsurdRetention(): void
    {
        $step = new OperationsStep($this->settings);

        $this->assertSame(['setup_ops_invalid'], $step->handle($this->post(['update_channel' => 'nightly', 'backup_keep' => '1']), 1));
        $this->assertSame(['setup_ops_invalid'], $step->handle($this->post(['update_channel' => 'release', 'backup_keep' => '9999']), 1));
        $this->assertSame(['setup_ops_invalid'], $step->handle($this->post(['update_channel' => 'release', 'backup_keep' => '-1']), 1));
        $this->assertSame('release', $this->settings->updateChannel(), 'rien n\'est enregistré en cas d\'erreur');
    }

    public function testOperationsShowsReadyToCopyCronCommands(): void
    {
        $data = (new OperationsStep($this->settings))->data(new Request());

        $this->assertStringContainsString('scripts/cron.php', $data['cron_command']);
        $this->assertStringContainsString('/cron/run/backup', $data['cron_url']);
        $this->assertStringContainsString('create-cron-token.php', $data['cron_token_command']);
    }

    // ── E-mail ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function mail(?\Closure $factory = null): MailStep
    {
        $mailer = new MailerService(['driver' => 'native', 'from' => ['address' => 'noreply@example.com']]);
        $step = new MailStep($mailer, new EnvFileWriter($this->envDir));
        if ($factory !== null) {
            $step->useMailerFactory($factory);
        }
        return $step;
    }

    public function testSmtpSettingsAreWrittenToTheEnvironmentFileAndTheOldPasswordIsKept(): void
    {
        putenv('MAIL_PASSWORD=ancien');
        $errors = $this->mail()->handle($this->post([
            'driver' => 'smtp', 'from' => 'noreply@exemple.fr', 'from_name' => 'Ma Boutique',
            'host' => 'smtp.exemple.fr', 'port' => '587', 'encryption' => 'tls', 'username' => 'noreply@exemple.fr', 'password' => '',
        ]), 1);

        $this->assertSame([], $errors);
        $env = (string) file_get_contents($this->envDir . '/.env');
        $this->assertStringContainsString('MAIL_DRIVER=smtp', $env);
        $this->assertStringContainsString('MAIL_HOST=smtp.exemple.fr', $env);
        $this->assertStringContainsString('MAIL_PORT=587', $env);
        $this->assertStringContainsString('MAIL_ENCRYPTION=tls', $env);
        $this->assertStringContainsString('MAIL_FROM_NAME="Ma Boutique"', $env);
        $this->assertStringNotContainsString('MAIL_PASSWORD', $env, 'mot de passe vide = actuel conservé, jamais écrasé');
        $this->assertStringContainsString('APP_ENV=local', $env, 'les autres lignes restent');
    }

    public function testANewSmtpPasswordIsWritten(): void
    {
        $this->mail()->handle($this->post(['driver' => 'smtp', 'from' => 'a@exemple.fr', 'host' => 'smtp.exemple.fr', 'port' => '465', 'encryption' => 'ssl', 'password' => 'secret']), 1);

        $this->assertStringContainsString('MAIL_PASSWORD=secret', (string) file_get_contents($this->envDir . '/.env'));
    }

    public function testMailValidationErrors(): void
    {
        $this->assertSame(['setup_mail_from_invalid'], $this->mail()->handle($this->post(['driver' => 'native', 'from' => 'nope']), 1));
        $this->assertSame(['setup_mail_smtp_invalid'], $this->mail()->handle($this->post(['driver' => 'smtp', 'from' => 'a@exemple.fr', 'host' => '', 'port' => '587', 'encryption' => 'tls']), 1));
        $this->assertSame(['setup_mail_smtp_invalid'], $this->mail()->handle($this->post(['driver' => 'smtp', 'from' => 'a@exemple.fr', 'host' => 'bad host;', 'port' => '587', 'encryption' => 'tls']), 1));
        $this->assertSame(['setup_mail_smtp_invalid'], $this->mail()->handle($this->post(['driver' => 'smtp', 'from' => 'a@exemple.fr', 'host' => 'smtp.exemple.fr', 'port' => '70000', 'encryption' => 'tls']), 1));
        $this->assertStringNotContainsString('MAIL_', (string) file_get_contents($this->envDir . '/.env'), 'rien n\'est écrit en cas d\'erreur');
    }

    public function testAnUnwritableEnvironmentFileIsReported(): void
    {
        $step = new MailStep(new MailerService(['driver' => 'native']), new EnvFileWriter($this->envDir . '/absent/dossier'));

        $this->assertSame(['setup_mail_not_saved'], $step->handle($this->post(['driver' => 'native', 'from' => 'a@exemple.fr']), 1));
    }

    public function testTheTestEmailTriesTheEnteredSettingsWithoutSavingThem(): void
    {
        $sent = [];
        $factory = function (array $config) use (&$sent): MailerService {
            $sent['config'] = $config;
            return new MailerService($config, new class($sent) implements \kintai\Core\Mail\MailTransportInterface {
                public function __construct(private array &$sent) {}

                public function send(string $fromAddress, string $fromName, array $to, string $subject, string $htmlBody, array $attachmentPaths = [], array $extraHeaders = []): bool
                {
                    $this->sent['to'] = $to;
                    return true;
                }
            });
        };

        $result = $this->mail($factory)->sendTest($this->post(['driver' => 'smtp', 'from' => 'a@exemple.fr', 'host' => 'smtp.exemple.fr', 'port' => '587', 'encryption' => 'tls', 'password' => 'p', 'test_to' => 'moi@exemple.fr']));

        $this->assertSame(['success' => true, 'error' => null], $result);
        $this->assertSame(['moi@exemple.fr'], $sent['to']);
        $this->assertSame('smtp.exemple.fr', $sent['config']['smtp']['host']);
        $this->assertSame('p', $sent['config']['smtp']['password']);
        $this->assertStringNotContainsString('MAIL_', (string) file_get_contents($this->envDir . '/.env'));
    }

    public function testTheTestEmailNeedsValidSettingsAndRecipient(): void
    {
        $this->assertSame('setup_mail_from_invalid', $this->mail()->sendTest($this->post(['driver' => 'native', 'from' => 'x']))['error']);
        $this->assertSame('mail_test_error_invalid_email', $this->mail()->sendTest($this->post(['driver' => 'native', 'from' => 'a@exemple.fr', 'test_to' => 'nope']))['error']);
    }

    // ── Premier magasin ─────────────────────────────────────────────────────────────────────────────────────────

    private function storeStep(StoreServiceInterface $service, array $existing = []): StoreStep
    {
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findAll')->willReturn($existing);
        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr']]);
        return new StoreStep($service, $stores, new InstanceLocale($languages));
    }

    public function testTheFirstStoreIsCreatedWithACodeDerivedFromItsNameAndTheOwnerIsAttached(): void
    {
        $service = $this->createMock(StoreServiceInterface::class);
        $service->expects($this->once())->method('createStore')->with($this->callback(function (array $data): bool {
            return $data['code'] === 'MABOUT' && $data['name'] === 'Ma Boutique' && $data['timezone'] === 'Asia/Tokyo' && $data['currency'] === 'JPY';
        }))->willReturn(['id' => 12]);
        $service->expects($this->once())->method('addMember')->with(12, 5);

        $errors = $this->storeStep($service)->handle($this->post(['name' => 'Ma Boutique', 'timezone' => 'Asia/Tokyo', 'currency' => 'jpy']), 5);

        $this->assertSame([], $errors);
    }

    public function testStoreValidationAndQuotaErrorsComeBackAsMessages(): void
    {
        $service = $this->createStub(StoreServiceInterface::class);
        $service->method('createStore')->willThrowException(new ValidationException(['name' => ['Le nom est requis.'], 'code' => 'Code requis.']));
        $this->assertSame(['Le nom est requis.', 'Code requis.'], $this->storeStep($service)->handle($this->post(['name' => 'X', 'timezone' => 'UTC']), 5));

        $quota = $this->createStub(StoreServiceInterface::class);
        $quota->method('createStore')->willThrowException(new PlanLimitExceededException('Limite de magasins atteinte.'));
        $this->assertSame(['Limite de magasins atteinte.'], $this->storeStep($quota)->handle($this->post(['name' => 'X', 'timezone' => 'UTC']), 5));
    }

    public function testAnUnknownTimezoneIsRefusedBeforeAnythingIsCreated(): void
    {
        $service = $this->createMock(StoreServiceInterface::class);
        $service->expects($this->never())->method('createStore');

        $this->assertSame(['setup_store_timezone_invalid'], $this->storeStep($service)->handle($this->post(['name' => 'X', 'timezone' => 'Mars/Olympus']), 5));
    }

    public function testExistingStoresAreListedAndSummarised(): void
    {
        $step = $this->storeStep($this->createStub(StoreServiceInterface::class), [['name' => 'Paris', 'is_active' => 1], ['name' => 'Fermé', 'is_active' => 0]]);

        $this->assertSame(['Paris'], $step->data(new Request())['existing']);
        $this->assertSame('Paris', $step->summary());
        $this->assertNull($this->storeStep($this->createStub(StoreServiceInterface::class))->summary());
    }

    // ── Notifications ───────────────────────────────────────────────────────────────────────────────────────────

    private function notificationsStep(NotificationService $notifications): NotificationsStep
    {
        $vapid = new VapidKeyStore($this->store);
        $subscriptions = $this->createStub(WebPushSubscriptionRepositoryInterface::class);
        $devices = $this->createStub(DevicePushTokenRepositoryInterface::class);
        $mailer = new MailerService(['driver' => 'native', 'from' => ['address' => 'noreply@example.com']]);
        $status = new NotificationChannelStatus($vapid, $subscriptions, $devices, $mailer, new PublicUrlResolver($this->settings, ''), $this->createStub(UserRepositoryInterface::class), []);
        return new NotificationsStep($status, $notifications);
    }

    public function testNotificationsStepSendsATestOnlyWhenAsked(): void
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('notify')->with(5, NotificationPreferenceService::TEST_TYPE, 'notif_test_body');
        $step = $this->notificationsStep($notifications);

        $this->assertSame([], $step->handle($this->post([]), 5), 'sans case cochée : rien n\'est envoyé');
        $this->assertSame([], $step->handle($this->post(['send_test' => '1']), 5));
        $this->assertArrayHasKey('web_push', $step->data(new Request())['status']);
    }
}
