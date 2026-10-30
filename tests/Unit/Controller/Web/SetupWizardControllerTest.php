<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Web;

use Illuminate\Container\Container;
use App\Exceptions\NotFoundException;
use Illuminate\Http\Request;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\BundleDiscoveryService;
use App\FeatureManager;
use App\InstalledBundleManifestStore;
use App\Models\AppSetting;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Services\BundleActivationService;
use App\Services\LicenseClientService;
use App\Services\PlanLimitService;
use App\Services\SetupWizard;
use App\Setup\SetupStepInterface;
use App\Setup\SetupSteps;
use Tests\Support\InMemoryAppSettings;
use App\Http\Controllers\System\SetupWizardController;
use Illuminate\Contracts\View\Factory as ViewFactory;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 4));
}

/** Parcours de l'assistant : passer, enregistrer, erreur de saisie, navigation par ?step=, fin. */
final class SetupWizardControllerTest extends TestCase
{
    private InMemoryAppSettings $store;
    private SetupWizard $wizard;
    private SetupWizardController $controller;
    /** @var list<array<string, mixed>> */
    public array $saved = [];
    /** @var list<string> */
    public array $errorsToReturn = [];
    /** @var list<array<string, string>> */
    public array $catalog = [];
    private string $views = '';

    protected function setUp(): void
    {
        $this->store = new InMemoryAppSettings();
        SetupWizard::start('release');

        $dir = sys_get_temp_dir() . '/kintai-setup-ctl-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $planLimits = new PlanLimitService(
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            new LicenseClientService(['base_url' => '', 'api_key' => '']),
        );
        $activation = new BundleActivationService(
            new FeatureManager([]),
            new BundleDiscoveryService($dir . '/legacy', new InstalledBundleManifestStore($dir . '/installed.json'), $dir, '1.0.0'),
            $planLimits,
        );
        $this->wizard = new SetupWizard(fn(string $channel): array => $this->catalog, fn(array $entry): ?string => null, $activation, $planLimits);

        // Une seule étape de formulaire factice suffit : le contrôleur ne connaît que l'interface.
        $fake = new class($this) implements SetupStepInterface {
            public function __construct(private SetupWizardControllerTest $test) {}

            public function id(): string { return 'organisation'; }

            public function data(Request $request): array { return ['name' => '', 'support_email' => '', 'public_url' => '', 'public_url_forced' => false, 'public_url_suggestion' => '']; }

            public function handle(Request $request, int $userId): array
            {
                $this->test->saved[] = ['user' => $userId];
                return $this->test->errorsToReturn;
            }

            public function summary(): ?string { return null; }
        };
        $steps = (new \ReflectionClass(SetupSteps::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SetupSteps::class, 'steps'))->setValue($steps, ['organisation' => $fake]);

        $router = kintai_test_registrar();
        foreach (['admin.setup', 'admin.setup.license', 'admin.setup.bundles', 'admin.setup.step', 'admin.setup.finish'] as $i => $name) {
            $router->post('/r' . $i . ($name === 'admin.setup.step' ? '/{step}' : ''), [\stdClass::class, 'x'], name: $name);
        }

        // Vues réelles de l'assistant, avec une mise en page réduite au contenu (la vraie demande tout le contexte d'une page).
        $this->views = sys_get_temp_dir() . '/kintai-setup-views-' . bin2hex(random_bytes(4));
        mkdir($this->views . '/layout', 0777, true);
        file_put_contents($this->views . '/layout/app.php', '<?php echo $content ?? "";');
        self::copyDir(BASE_PATH . '/resources/views/system', $this->views . '/system');
        $view = kintai_test_views($this->views);
        $view->share('BASE_URL', '');
        $this->controller = new SetupWizardController(
            $view,
            $this->wizard,
            (new \ReflectionClass(LicenseClientService::class))->newInstanceWithoutConstructor(),
            new AppSettingsService(),
            new AuditLogger(),
            $steps,
            new \App\Setup\SetupQuestionnaire([
                ['id' => 'timeoff', 'bundles' => ['timeoff'], 'icon' => 'x', 'theme' => 'requests'],
                ['id' => 'timeclock', 'bundles' => ['timeclock'], 'icon' => 'y', 'theme' => 'planning'],
            ]),
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        self::removeDir($this->views);
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($from . '/' . $entry) ? self::copyDir($from . '/' . $entry, $to . '/' . $entry) : copy($from . '/' . $entry, $to . '/' . $entry);
        }
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($dir . '/' . $entry) ? self::removeDir($dir . '/' . $entry) : unlink($dir . '/' . $entry);
        }
        rmdir($dir);
    }

    private function request(array $post = [], array $params = [], array $query = []): Request
    {
        $_POST = $post;
        $_GET = $query;
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => 5, 'is_admin' => 1]);
        $req->setRouteParams($params);
        return $req;
    }

    public function testSavingAStepMarksItDoneAndMovesToTheNextOne(): void
    {
        $response = $this->controller->step($this->request([], ['step' => 'organisation']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('?step=store', (string) ($response->headers->get('Location') ?? ''));
        $this->assertSame(SetupWizard::DONE, $this->wizard->progress()['organisation']);
        $this->assertSame([['user' => 5]], $this->saved);
    }

    public function testSkippingMarksTheStepSkippedWithoutSavingAnything(): void
    {
        $response = $this->controller->step($this->request(['skip' => '1'], ['step' => 'organisation']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(SetupWizard::SKIPPED, $this->wizard->progress()['organisation']);
        $this->assertSame([], $this->saved);
    }

    public function testInputErrorsKeepTheStepOpenAndShowTheMessages(): void
    {
        $this->errorsToReturn = ['setup_org_name_required'];

        $response = $this->controller->step($this->request([], ['step' => 'organisation']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('setup_org_name_required', $response->getContent());
        $this->assertNull($this->wizard->progress()['organisation'], 'rien n\'est marqué fait');
    }

    public function testAnUnknownStepIsRejected(): void
    {
        $this->expectException(NotFoundException::class);

        $this->controller->step($this->request([], ['step' => '../../etc/passwd']));
    }

    public function testShowOpensTheRequestedStepOtherwiseTheFirstOneToDo(): void
    {
        $this->assertStringContainsString('name="support_email"', $this->controller->show($this->request([], [], ['step' => 'organisation']))->getContent());

        // Étape inconnue dans l'URL : on retombe sur la première à faire.
        $response = $this->controller->show($this->request([], [], ['step' => 'not-a-step']));
        $this->assertStringContainsString('name="support_email"', $response->getContent());
        $this->assertStringContainsString('aria-current="step"', $response->getContent());
    }

    public function testAnsweringTheQuestionnaireShowsTheSelectionWithTheRecommendedBundlesChecked(): void
    {
        $this->catalog = [
            ['slug' => 'timeoff', 'name' => 'Congés', 'description' => 'd', 'version' => '1.0.0', 'repository_url' => '', 'registry_url' => ''],
            ['slug' => 'timeclock', 'name' => 'Pointage', 'description' => 'd', 'version' => '1.0.0', 'repository_url' => '', 'registry_url' => ''],
        ];

        $html = $this->controller->questions($this->request(['answers' => ['timeclock', 'inconnue']]))->getContent();

        $this->assertMatchesRegularExpression('/value="timeclock"[^>]*checked/s', $html);
        $this->assertDoesNotMatchRegularExpression('/value="timeoff"[^>]*checked/s', $html);
        $this->assertStringContainsString('data-room="4"', $html, 'plan gratuit : 4 bundles');
    }

    public function testFinishEndsTheWizardAndShowIsThenRedirected(): void
    {
        $response = $this->controller->finish($this->request());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->wizard->isPending());
        $this->assertSame(302, $this->controller->show($this->request())->getStatusCode());
    }
}
