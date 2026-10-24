<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controllers;

use kintai\Core\Container;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\Log;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\EnvFileWriter;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\UI\Controller\Web\System\OwnerSettingsController;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class OwnerSettingsControllerTest extends TestCase
{
    /** Snapshot de app_settings vu par le test courant — modifié en place par setMany(). */
    private array $stored = [];

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/system';
        $layoutDir = sys_get_temp_dir() . '/layout';
        foreach ([$viewDir, $layoutDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
        touch($viewDir . '/owner-settings.php');
        touch($layoutDir . '/app.php');

        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->createStub(LogRepositoryInterface::class));
        Log::setContainer($container);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        Log::reset();
    }

    /**
     * Construit un contrôleur dont l'AppSettingsService voit $initialStored comme état
     * initial — doit être appelé APRÈS avoir préparé $this->stored, car AppSettingsService
     * met en cache repo->all() une seule fois, à la construction.
     */
    private function makeController(array $initialStored = [], string $envUrl = ''): OwnerSettingsController
    {
        $this->stored = $initialStored;

        $repo = $this->createStub(AppSettingsRepositoryInterface::class);
        $repo->method('all')->willReturnCallback(fn() => $this->stored);
        $repo->method('setMany')->willReturnCallback(function (array $settings): void {
            foreach ($settings as $k => $v) {
                $this->stored[(string) $k] = (string) $v;
            }
        });

        $view = new ViewRenderer(sys_get_temp_dir());

        $settings = new AppSettingsService($repo);

        return new OwnerSettingsController($view, $settings, new AuditLogger(), new PublicUrlResolver($settings, $envUrl));
    }

    public function testShowRendersWithDefaultFoxyColors(): void
    {
        $controller = $this->makeController();

        $response = $controller->show(new Request());

        $this->assertSame(200, $response->status());
    }

    public function testSaveStoresACustomLightColor(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_success_color' => '#00FF00'];

        $controller->save(new Request());

        $this->assertSame('#00ff00', $this->stored['app_success_color']);
    }

    public function testSaveFallsBackToPreviousValueOnInvalidHex(): void
    {
        $controller = $this->makeController(['app_danger_color' => '#123456']);
        $_POST = ['app_danger_color' => 'not-a-color'];

        $controller->save(new Request());

        $this->assertSame('#123456', $this->stored['app_danger_color']);
    }

    public function testSavePersistsDarkModeToggle(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_theme_dark_mode' => 'manual'];

        $controller->save(new Request());

        $this->assertSame('manual', $this->stored['app_theme_dark_mode']);
    }

    public function testSaveRejectsUnknownDarkModeValue(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_theme_dark_mode' => 'nightly'];

        $controller->save(new Request());

        $this->assertSame('auto', $this->stored['app_theme_dark_mode']);
    }

    public function testSaveStoresAManualDarkColor(): void
    {
        $controller = $this->makeController();
        $_POST = [
            'app_theme_dark_mode'    => 'manual',
            'app_primary_color_dark' => '#112233',
        ];

        $controller->save(new Request());

        $this->assertSame('#112233', $this->stored['app_primary_color_dark']);
    }

    public function testSaveClearsAnInvalidManualDarkColorToEmptyMeaningAuto(): void
    {
        $controller = $this->makeController(['app_primary_color_dark' => '#112233']);
        $_POST = ['app_primary_color_dark' => 'nope'];

        $controller->save(new Request());

        $this->assertSame('', $this->stored['app_primary_color_dark']);
    }

    public function testSaveDefaultsAccessLogEnabledToOffWhenCheckboxUnchecked(): void
    {
        $controller = $this->makeController(['access_log_enabled' => '1']);
        $_POST = ['access_log_enabled' => '0'];

        $controller->save(new Request());

        $this->assertSame('0', $this->stored['access_log_enabled']);
    }

    public function testSavePersistsAccessLogEnabled(): void
    {
        $controller = $this->makeController();
        $_POST = ['access_log_enabled' => '1'];

        $controller->save(new Request());

        $this->assertSame('1', $this->stored['access_log_enabled']);
    }

    public function testSaveClampsLogRetentionDaysToRange(): void
    {
        $controller = $this->makeController();
        $_POST = ['log_retention_days' => '99999'];

        $controller->save(new Request());

        $this->assertSame('3650', $this->stored['log_retention_days']);
    }

    public function testSaveAllowsZeroLogRetentionDaysMeaningUnlimited(): void
    {
        $controller = $this->makeController();
        $_POST = ['log_retention_days' => '0'];

        $controller->save(new Request());

        $this->assertSame('0', $this->stored['log_retention_days']);
    }

    public function testSaveStoresANormalizedPublicUrl(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_public_url' => 'HTTPS://Kintai.Example.com/app/'];

        $controller->save(new Request());

        $this->assertSame('https://kintai.example.com/app', $this->stored[PublicUrlResolver::SETTING_KEY]);
    }

    public function testSaveRefusesAnInvalidPublicUrlAndKeepsThePreviousOne(): void
    {
        // Un chemin seul (« /Kintai ») donnerait à nouveau des liens d'e-mail inutilisables : refusé.
        $controller = $this->makeController([PublicUrlResolver::SETTING_KEY => 'https://old.example.com']);
        $_POST = ['app_public_url' => '/Kintai', 'app_subtitle' => 'Changed'];

        $response = $controller->save(new Request());

        $this->assertSame('https://old.example.com', $this->stored[PublicUrlResolver::SETTING_KEY]);
        $this->assertArrayNotHasKey('app_subtitle', $this->stored, 'Rien ne doit être enregistré quand l\'URL est refusée.');
        $this->assertStringContainsString('error=public_url_invalid', $this->locationOf($response));
    }

    public function testSaveAcceptsAnEmptyPublicUrl(): void
    {
        $controller = $this->makeController([PublicUrlResolver::SETTING_KEY => 'https://old.example.com']);
        $_POST = ['app_public_url' => ''];

        $controller->save(new Request());

        $this->assertSame('', $this->stored[PublicUrlResolver::SETTING_KEY]);
    }

    private function locationOf(\kintai\Core\Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'headers');

        return $ref->getValue($response)['Location'] ?? '';
    }

    public function testSaveRedirectsWithSuccessFlag(): void
    {
        $controller = $this->makeController();

        $response = $controller->save(new Request());

        $this->assertSame(302, $response->status());
    }

    /** Limite de débit de l'API (audit M9) : réglable par l'Owner, 0 = désactivée, valeur bornée. */
    public function testSaveStoresTheApiRateLimit(): void
    {
        $controller = $this->makeController();
        $_POST = ['api_rate_limit_per_minute' => '120'];

        $controller->save(new Request());

        $this->assertSame('120', $this->stored['api_rate_limit_per_minute']);
    }

    public function testSaveClampsTheApiRateLimit(): void
    {
        $controller = $this->makeController();
        $_POST = ['api_rate_limit_per_minute' => '-5'];
        $controller->save(new Request());
        $this->assertSame('0', $this->stored['api_rate_limit_per_minute']);

        $_POST = ['api_rate_limit_per_minute' => '999999999'];
        $controller->save(new Request());
        $this->assertSame('100000', $this->stored['api_rate_limit_per_minute']);
    }

    public function testSaveKeepsTheCurrentApiRateLimitWhenTheFieldIsAbsent(): void
    {
        $controller = $this->makeController();
        $_POST = [];

        $controller->save(new Request());

        $this->assertSame('300', $this->stored['api_rate_limit_per_minute']);
    }

    /** Audit M12 : une requête partielle ne coupe plus le journal ni la maintenance, n'efface plus les textes. */
    public function testFieldsAbsentFromTheRequestKeepTheirValue(): void
    {
        $controller = $this->makeController([
            'access_log_enabled' => '1', 'maintenance_mode_enabled' => '1', 'maintenance_message' => 'Travaux',
            'app_subtitle' => 'Kintai Tokyo', 'app_login_notice' => 'Bienvenue', 'log_retention_days' => '30',
        ]);
        $_POST = [];

        $controller->save(new Request());

        $this->assertSame('1', $this->stored['access_log_enabled']);
        $this->assertSame('1', $this->stored['maintenance_mode_enabled']);
        $this->assertSame('Travaux', $this->stored['maintenance_message']);
        $this->assertSame('Kintai Tokyo', $this->stored['app_subtitle']);
        $this->assertSame('Bienvenue', $this->stored['app_login_notice']);
        $this->assertSame('30', $this->stored['log_retention_days']);
    }

    private function makeControllerWithLocale(string $envDir): OwnerSettingsController
    {
        $base = $this->makeController();
        $languages = $this->createStub(LanguageRepositoryInterface::class);
        $languages->method('findAllActive')->willReturn([['code' => 'fr', 'name' => 'Français'], ['code' => 'ja', 'name' => '日本語']]);
        $languages->method('findDefault')->willReturn(['code' => 'fr']);
        $settings = (new \ReflectionProperty($base, 'settings'))->getValue($base);

        return new OwnerSettingsController(
            new ViewRenderer(sys_get_temp_dir()), $settings, new AuditLogger(), new PublicUrlResolver($settings, ''),
            new InstanceLocale($languages, new EnvFileWriter($envDir)), $languages,
        );
    }

    public function testSaveWritesTheInstanceLanguageToTheEnvironmentFile(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-owner-locale-' . uniqid();
        mkdir($dir);
        try {
            $_POST = ['app_locale' => 'ja'];
            $response = $this->makeControllerWithLocale($dir)->save(new Request());

            $this->assertSame("APP_LOCALE=ja\n", file_get_contents($dir . '/.env'));
            $this->assertStringContainsString('success=1', (string) ($response->headers()['Location'] ?? ''));
        } finally {
            @unlink($dir . '/.env');
            @rmdir($dir);
            unset($_ENV['APP_LOCALE'], $_SERVER['APP_LOCALE']);
            putenv('APP_LOCALE');
        }
    }

    public function testAnUnwritableEnvironmentFileIsReportedAndOtherSettingsAreKept(): void
    {
        $_POST = ['app_locale' => 'ja', 'app_subtitle' => 'Ma société'];
        $response = $this->makeControllerWithLocale(sys_get_temp_dir() . '/kintai-missing-dir-' . uniqid())->save(new Request());

        $this->assertStringContainsString('error=instance_locale_not_saved', (string) ($response->headers()['Location'] ?? ''));
        $this->assertSame('Ma société', $this->stored['app_subtitle']);
    }
}
