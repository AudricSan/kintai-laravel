<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Illuminate\Container\Container;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use App\Services\PublicUrlResolver;
use App\Services\EnvFileWriter;
use App\Services\InstanceLocale;
use App\Http\Controllers\System\OwnerSettingsController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

final class OwnerSettingsControllerTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    /**
     * Construit un contrôleur dont l'AppSettingsService voit $initialStored comme état
     * initial — les réglages sont écrits dans la base de test avant la construction du service.
     */
    private function makeController(array $initialStored = [], string $envUrl = ''): OwnerSettingsController
    {
        kintai_test_settings($initialStored);

        $view = kintai_test_views(sys_get_temp_dir());

        $settings = new AppSettingsService();

        return new OwnerSettingsController($view, $settings, new AuditLogger(), new PublicUrlResolver($settings, $envUrl));
    }

    public function testShowRendersWithDefaultFoxyColors(): void
    {
        $controller = $this->makeController();

        $response = $controller->show(Request::capture());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSaveStoresACustomLightColor(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_success_color' => '#00FF00'];

        $controller->save(Request::capture());

        $this->assertSame('#00ff00', AppSetting::map()['app_success_color']);
    }

    public function testSaveFallsBackToPreviousValueOnInvalidHex(): void
    {
        $controller = $this->makeController(['app_danger_color' => '#123456']);
        $_POST = ['app_danger_color' => 'not-a-color'];

        $controller->save(Request::capture());

        $this->assertSame('#123456', AppSetting::map()['app_danger_color']);
    }

    public function testSavePersistsDarkModeToggle(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_theme_dark_mode' => 'manual'];

        $controller->save(Request::capture());

        $this->assertSame('manual', AppSetting::map()['app_theme_dark_mode']);
    }

    public function testSaveRejectsUnknownDarkModeValue(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_theme_dark_mode' => 'nightly'];

        $controller->save(Request::capture());

        $this->assertSame('auto', AppSetting::map()['app_theme_dark_mode']);
    }

    public function testSaveStoresAManualDarkColor(): void
    {
        $controller = $this->makeController();
        $_POST = [
            'app_theme_dark_mode'    => 'manual',
            'app_primary_color_dark' => '#112233',
        ];

        $controller->save(Request::capture());

        $this->assertSame('#112233', AppSetting::map()['app_primary_color_dark']);
    }

    public function testSaveClearsAnInvalidManualDarkColorToEmptyMeaningAuto(): void
    {
        $controller = $this->makeController(['app_primary_color_dark' => '#112233']);
        $_POST = ['app_primary_color_dark' => 'nope'];

        $controller->save(Request::capture());

        $this->assertSame('', AppSetting::map()['app_primary_color_dark']);
    }

    public function testSaveDefaultsAccessLogEnabledToOffWhenCheckboxUnchecked(): void
    {
        $controller = $this->makeController(['access_log_enabled' => '1']);
        $_POST = ['access_log_enabled' => '0'];

        $controller->save(Request::capture());

        $this->assertSame('0', AppSetting::map()['access_log_enabled']);
    }

    public function testSavePersistsAccessLogEnabled(): void
    {
        $controller = $this->makeController();
        $_POST = ['access_log_enabled' => '1'];

        $controller->save(Request::capture());

        $this->assertSame('1', AppSetting::map()['access_log_enabled']);
    }

    public function testSaveClampsLogRetentionDaysToRange(): void
    {
        $controller = $this->makeController();
        $_POST = ['log_retention_days' => '99999'];

        $controller->save(Request::capture());

        $this->assertSame('3650', AppSetting::map()['log_retention_days']);
    }

    public function testSaveAllowsZeroLogRetentionDaysMeaningUnlimited(): void
    {
        $controller = $this->makeController();
        $_POST = ['log_retention_days' => '0'];

        $controller->save(Request::capture());

        $this->assertSame('0', AppSetting::map()['log_retention_days']);
    }

    public function testSaveStoresANormalizedPublicUrl(): void
    {
        $controller = $this->makeController();
        $_POST = ['app_public_url' => 'HTTPS://Kintai.Example.com/app/'];

        $controller->save(Request::capture());

        $this->assertSame('https://kintai.example.com/app', AppSetting::map()[PublicUrlResolver::SETTING_KEY]);
    }

    public function testSaveRefusesAnInvalidPublicUrlAndKeepsThePreviousOne(): void
    {
        // Un chemin seul (« /Kintai ») donnerait à nouveau des liens d'e-mail inutilisables : refusé.
        $controller = $this->makeController([PublicUrlResolver::SETTING_KEY => 'https://old.example.com']);
        $_POST = ['app_public_url' => '/Kintai', 'app_subtitle' => 'Changed'];

        $response = $controller->save(Request::capture());

        $this->assertSame('https://old.example.com', AppSetting::map()[PublicUrlResolver::SETTING_KEY]);
        $this->assertArrayNotHasKey('app_subtitle', AppSetting::map(), 'Rien ne doit être enregistré quand l\'URL est refusée.');
        $this->assertStringContainsString('public_url_invalid', (string) session('error'));
    }

    public function testSaveAcceptsAnEmptyPublicUrl(): void
    {
        $controller = $this->makeController([PublicUrlResolver::SETTING_KEY => 'https://old.example.com']);
        $_POST = ['app_public_url' => ''];

        $controller->save(Request::capture());

        $this->assertSame('', AppSetting::map()[PublicUrlResolver::SETTING_KEY]);
    }

    private function locationOf(\Symfony\Component\HttpFoundation\Response $response): string
    {
        return kintai_test_headers($response)['Location'] ?? '';
    }

    public function testSaveRedirectsWithSuccessFlag(): void
    {
        $controller = $this->makeController();

        $response = $controller->save(Request::capture());

        $this->assertSame(302, $response->getStatusCode());
    }

    /** Limite de débit de l'API (audit M9) : réglable par l'Owner, 0 = désactivée, valeur bornée. */
    public function testSaveStoresTheApiRateLimit(): void
    {
        $controller = $this->makeController();
        $_POST = ['api_rate_limit_per_minute' => '120'];

        $controller->save(Request::capture());

        $this->assertSame('120', AppSetting::map()['api_rate_limit_per_minute']);
    }

    public function testSaveClampsTheApiRateLimit(): void
    {
        $controller = $this->makeController();
        $_POST = ['api_rate_limit_per_minute' => '-5'];
        $controller->save(Request::capture());
        $this->assertSame('0', AppSetting::map()['api_rate_limit_per_minute']);

        $_POST = ['api_rate_limit_per_minute' => '999999999'];
        $controller->save(Request::capture());
        $this->assertSame('100000', AppSetting::map()['api_rate_limit_per_minute']);
    }

    public function testSaveKeepsTheCurrentApiRateLimitWhenTheFieldIsAbsent(): void
    {
        $controller = $this->makeController();
        $_POST = [];

        $controller->save(Request::capture());

        $this->assertSame('300', AppSetting::map()['api_rate_limit_per_minute']);
    }

    /** Audit M12 : une requête partielle ne coupe plus le journal ni la maintenance, n'efface plus les textes. */
    public function testFieldsAbsentFromTheRequestKeepTheirValue(): void
    {
        $controller = $this->makeController([
            'access_log_enabled' => '1', 'maintenance_mode_enabled' => '1', 'maintenance_message' => 'Travaux',
            'app_subtitle' => 'Kintai Tokyo', 'app_login_notice' => 'Bienvenue', 'log_retention_days' => '30',
        ]);
        $_POST = [];

        $controller->save(Request::capture());

        $this->assertSame('1', AppSetting::map()['access_log_enabled']);
        $this->assertSame('1', AppSetting::map()['maintenance_mode_enabled']);
        $this->assertSame('Travaux', AppSetting::map()['maintenance_message']);
        $this->assertSame('Kintai Tokyo', AppSetting::map()['app_subtitle']);
        $this->assertSame('Bienvenue', AppSetting::map()['app_login_notice']);
        $this->assertSame('30', AppSetting::map()['log_retention_days']);
    }

    private function makeControllerWithLocale(string $envDir): OwnerSettingsController
    {
        $base = $this->makeController();
        kintai_test_languages(['fr', 'ja']);
        $settings = (new \ReflectionProperty($base, 'settings'))->getValue($base);

        return new OwnerSettingsController(
            kintai_test_views(sys_get_temp_dir()), $settings, new AuditLogger(), new PublicUrlResolver($settings, ''),
            new InstanceLocale(new EnvFileWriter($envDir)),
        );
    }

    public function testSaveWritesTheInstanceLanguageToTheEnvironmentFile(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-owner-locale-' . uniqid();
        mkdir($dir);
        try {
            $_POST = ['app_locale' => 'ja'];
            $response = $this->makeControllerWithLocale($dir)->save(Request::capture());

            $this->assertSame("APP_LOCALE=ja\n", file_get_contents($dir . '/.env'));
            $this->assertStringContainsString('1', (string) session('success'));
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
        $response = $this->makeControllerWithLocale(sys_get_temp_dir() . '/kintai-missing-dir-' . uniqid())->save(Request::capture());

        $this->assertStringContainsString('instance_locale_not_saved', (string) session('error'));
        $this->assertSame('Ma société', AppSetting::map()['app_subtitle']);
    }
}
