<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Services;

use App\BundleDiscoveryService;
use App\FeatureManager;
use App\InstalledBundleManifestStore;
use App\Models\AppSetting;
use App\Models\Store;
use App\Repositories\UserRepositoryInterface;
use App\Services\BundleActivationService;
use App\Services\LicenseClientService;
use App\Services\PlanLimitService;
use App\Services\SetupWizard;
use PHPUnit\Framework\TestCase;

/**
 * Assistant de configuration qui suit l'installateur : état (étapes, bandeau), choix des bundles limité par la
 * licence (plan gratuit : 4), installation avec échecs rapportés et activation dans le quota.
 */
final class SetupWizardTest extends TestCase
{
    /** Catalogue factice : cinq bundles. */
    private static function catalog(): \Closure
    {
        return static fn(string $channel): array => array_map(static fn(string $slug): array => [
            'slug' => $slug, 'name' => ucfirst($slug), 'description' => '', 'version' => $channel === 'alpha' ? '1.2.1' : '1.2.0',
            'repository_url' => 'https://github.com/AudricSan/kintai-bundle-' . $slug, 'registry_url' => 'https://registry.test',
        ], ['timeoff', 'timeclock', 'shift-swap', 'notebook', 'messaging']);
    }

    /**
     * @param list<string>          $installed bundles « installés » (présents pour la découverte une fois installés)
     * @param array<string, string> $failures  slug => motif d'échec de l'installation
     */
    private function wizard(array &$installed, array $failures = []): SetupWizard
    {
        $dir = sys_get_temp_dir() . '/kintai-setup-' . uniqid();
        mkdir($dir, 0777, true);
        $store = new InstalledBundleManifestStore($dir . '/installed.json');
        $installer = function (array $entry) use (&$installed, $failures, $store, $dir): ?string {
            if (isset($failures[$entry['slug']])) {
                return $failures[$entry['slug']];
            }
            $installed[] = [$entry['slug'], $entry['version']];
            self::writeInstalledBundle($dir, $entry['slug'], $entry['version']);
            $store->setActiveVersion($entry['slug'], $entry['version']);
            return null;
        };
        $planLimits = new PlanLimitService(
            $this->createStub(UserRepositoryInterface::class),
            new LicenseClientService(['base_url' => '', 'api_key' => '']),
        );
        $activation = new BundleActivationService(new FeatureManager([]), new BundleDiscoveryService($dir . '/legacy', $store, $dir, '1.0.0'), $planLimits);

        return new SetupWizard(self::catalog(), $installer, $activation, $planLimits);
    }

    /** Bundle installé minimal (manifeste avec contrat, classe d'entrée) pour que la découverte le voie. */
    private static function writeInstalledBundle(string $dir, string $slug, string $version): void
    {
        $class = 'SetupFake_' . str_replace('-', '_', $slug) . '_' . substr(md5($dir), 0, 8);
        $ns = 'kintai\\Bundles\\Installed\\' . $class;
        $root = $dir . '/' . $slug . '/' . $version;
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/bundle.json', json_encode(['slug' => $slug, 'name' => $slug, 'version' => $version, 'namespace' => $ns,
            'entry_class' => $ns . '\\' . $class . 'Bundle', 'kintai_contract' => '^1.0']));
        file_put_contents($root . '/src/' . $class . 'Bundle.php', "<?php\nnamespace {$ns};\nfinal class {$class}Bundle extends \\App\\Bundles\\Bundle {\n    public function getName(): string { return '{$slug}'; }\n    public function register(): void {}\n}\n");
        require $root . '/src/' . $class . 'Bundle.php';
    }

    public function testStartPutsANewInstanceOnTheFirstStepWithoutActiveBundles(): void
    {
        kintai_test_settings();
        SetupWizard::start('alpha');

        $this->assertSame('[]', AppSetting::value('enabled_bundles'));
        $this->assertSame('alpha', AppSetting::value('bundle_update_channel'));
        $installed = [];
        $wizard = $this->wizard($installed);
        $this->assertTrue($wizard->isPending());
        $this->assertSame(SetupWizard::STEP_ORGANISATION, $wizard->step());

        $wizard->finish();
        $this->assertFalse($wizard->isPending());
    }

    public function testStepsComeInOrderAndDoneOrSkippedOnesAreLeftBehind(): void
    {
        kintai_test_settings();
        SetupWizard::start('release');
        $installed = [];
        $wizard = $this->wizard($installed);

        $walked = [];
        while (($step = $wizard->step()) !== SetupWizard::STEP_RECAP) {
            $walked[] = $step;
            $wizard->mark($step, count($walked) % 2 === 0);
        }

        $this->assertSame(['organisation', 'store', 'mail', 'notifications', 'operations', 'bundles', 'license'], $walked, 'Bundles avant Licence');
        $this->assertSame(SetupWizard::DONE, $wizard->progress()['organisation']);
        $this->assertSame(SetupWizard::SKIPPED, $wizard->progress()['store']);
    }

    public function testAStepCanBeRevisitedAndUnknownOnesAreIgnored(): void
    {
        kintai_test_settings();
        SetupWizard::start('release');
        $installed = [];
        $wizard = $this->wizard($installed);
        $wizard->mark('organisation');
        $wizard->mark('nonexistent');
        $wizard->mark(SetupWizard::STEP_RECAP);

        $this->assertSame('store', $wizard->step());
        $this->assertSame(['organisation', 'store', 'mail', 'notifications', 'operations', 'bundles', 'license'], array_keys($wizard->progress()));
        $this->assertTrue(SetupWizard::isStep('mail'));
        $this->assertFalse(SetupWizard::isStep('../etc'));
    }

    public function testNavigationBetweenSteps(): void
    {
        $this->assertSame('store', SetupWizard::nextStep('organisation'));
        $this->assertSame('recap', SetupWizard::nextStep('license'));
        $this->assertSame('recap', SetupWizard::nextStep('recap'));
        $this->assertNull(SetupWizard::previousStep('organisation'));
        $this->assertSame('bundles', SetupWizard::previousStep('license'));
        $this->assertSame(1, SetupWizard::position('organisation'));
        $this->assertSame(8, SetupWizard::position('recap'));
    }

    public function testProgressOfAnInstanceInTheOldTwoStepWizardIsKept(): void
    {
        kintai_test_settings();
        AppSetting::putMany([SetupWizard::PENDING_KEY => '1', SetupWizard::STEP_KEY => 'bundles']);
        $installed = [];
        $wizard = $this->wizard($installed);

        $this->assertSame(SetupWizard::DONE, $wizard->progress()['license'], 'la licence était déjà faite');
        $this->assertSame('organisation', $wizard->step(), 'les nouvelles étapes lui sont proposées');
    }

    public function testAnInstanceInstalledBeforeTheWizardIsNotConcerned(): void
    {
        kintai_test_settings();
        $installed = [];
        $this->assertFalse($this->wizard($installed)->isPending());
    }

    public function testChoicesOutsideTheCatalogueOrAboveTheLicenseAreRefused(): void
    {
        kintai_test_settings();
        $installed = [];
        $wizard = $this->wizard($installed);

        $this->assertSame('setup_bundles_unknown', $wizard->install(['timeoff', 'pirate'], 'release')['error']);
        $this->assertSame([], $installed, 'rien installé');
    }

    public function testBundlesBeyondTheQuotaAreRememberedNotRefused(): void
    {
        kintai_test_settings();
        $installed = [];
        $wizard = $this->wizard($installed);

        $result = $wizard->install(['timeoff', 'timeclock', 'shift-swap', 'notebook', 'messaging'], 'release');

        $this->assertNull($result['error']);
        $this->assertSame(['timeoff', 'timeclock', 'shift-swap', 'notebook'], $result['installed'], 'les 4 premiers (plan gratuit)');
        $this->assertSame(['messaging'], $result['pending']);
        $this->assertSame(['messaging'], $wizard->pendingBundles(), 'mémorisé, pas téléchargé');
        $this->assertCount(4, $installed);
    }

    public function testPendingBundlesInstallInOrderOnceThereIsRoom(): void
    {
        kintai_test_settings();
        $installed = [];
        $wizard = $this->wizard($installed);
        $wizard->install(['timeoff', 'timeclock', 'shift-swap', 'notebook', 'messaging'], 'release');
        $this->assertSame(['messaging'], $wizard->pendingBundles());

        // Pas de place : rien ne bouge, le bundle reste en attente.
        $none = $wizard->installPending('release');
        $this->assertSame([], $none['installed']);
        $this->assertSame(['messaging'], $none['pending']);

        // Une place se libère (ici : un bundle désactivé) : le bundle en attente s'installe.
        AppSetting::put('enabled_bundles', json_encode(['timeoff', 'timeclock', 'shift-swap']));
        $result = $wizard->installPending('release');

        $this->assertSame(['messaging'], $result['installed']);
        $this->assertSame([], $result['pending']);
        $this->assertSame([], $wizard->pendingBundles());
    }

    public function testAFailedPendingInstallStaysPending(): void
    {
        kintai_test_settings();
        $installed = [];
        $wizard = $this->wizard($installed, ['messaging' => 'réseau']);
        $wizard->install(['timeoff', 'timeclock', 'shift-swap', 'notebook', 'messaging'], 'release');
        AppSetting::put('enabled_bundles', json_encode(['timeoff']));

        $result = $wizard->installPending('release');

        $this->assertSame(['messaging' => 'réseau'], $result['failed']);
        $this->assertSame(['messaging'], $result['pending']);
        $this->assertSame(['messaging'], $wizard->pendingBundles(), 'il pourra être réessayé');
    }

    public function testNothingPendingMeansNothingToDo(): void
    {
        kintai_test_settings();
        $installed = [];
        $result = $this->wizard($installed)->installPending('release');

        $this->assertSame([], $result['installed']);
        $this->assertSame([], $result['pending']);
        $this->assertSame([], $installed);
    }

    public function testAnotherChoiceReplacesTheRememberedList(): void
    {
        kintai_test_settings();
        $installed = [];
        $wizard = $this->wizard($installed);
        $wizard->install(['timeoff', 'timeclock', 'shift-swap', 'notebook', 'messaging'], 'release');

        $wizard->install([], 'release');

        $this->assertSame([], $wizard->pendingBundles());
    }

    public function testChosenBundlesAreInstalledOnTheChannelAndEnabledFailuresReported(): void
    {
        kintai_test_settings();
        SetupWizard::start('alpha');
        $installed = [];
        $wizard = $this->wizard($installed, ['shift-swap' => 'Release introuvable']);

        $result = $wizard->install(['timeoff', 'shift-swap', 'timeclock'], 'alpha');

        $this->assertNull($result['error']);
        $this->assertSame(['timeoff', 'timeclock'], $result['installed']);
        $this->assertSame(['shift-swap' => 'Release introuvable'], $result['failed']);
        $this->assertSame([], $result['inactive']);
        $this->assertSame([['timeoff', '1.2.1'], ['timeclock', '1.2.1']], $installed, 'versions du canal alpha');
        $this->assertSame(['timeoff', 'timeclock'], json_decode((string) AppSetting::value('enabled_bundles'), true));
    }
}
