<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\FeatureManager;
use App\LicenseServiceProvider;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

// BASE_PATH est requis par LicenseServiceProvider (fallback vers config/license.php).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

final class LicenseServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::setConnectionResolver($GLOBALS['kintai_test_app']['db']);
    }

    /** Les modèles Eloquent tombent sur une connexion qui lève l'erreur donnée (base indisponible ou bogue). */
    private static function failingConnections(\Throwable $error): void
    {
        Model::setConnectionResolver(new class($error) implements ConnectionResolverInterface {
            public function __construct(private \Throwable $error) {}

            public function connection($name = null)
            {
                throw $this->error;
            }

            public function getDefaultConnection()
            {
                return 'testing';
            }

            public function setDefaultConnection($name): void {}
        });
    }

    public function testUsesEnabledBundlesFromAppSettingsWhenPresent(): void
    {
        $container = new \Tests\Support\TestContainer();
        kintai_test_settings([LicenseServiceProvider::SETTINGS_KEY => '["daily-report","store-photos"]']);

        (new LicenseServiceProvider($container))->register();

        $features = $container->make(FeatureManager::class);
        $this->assertTrue($features->isEnabled('daily-report'));
        $this->assertTrue($features->isEnabled('store-photos'));
        $this->assertFalse($features->isEnabled('messaging'));
    }

    public function testFallsBackToConfigFileWhenNoAppSettingsValueStored(): void
    {
        $container = new \Tests\Support\TestContainer();
        kintai_test_settings();

        (new LicenseServiceProvider($container))->register();

        $features = $container->make(FeatureManager::class);
        // config/license.php (celui du dépôt de test) active daily-report + messaging par défaut.
        $this->assertTrue($features->isEnabled('daily-report'));
        $this->assertTrue($features->isEnabled('messaging'));
    }

    public function testANotYetMigratedDatabaseFallsBackToTheConfigFileInsteadOfCrashing(): void
    {
        // Ce fournisseur tourne dans le constructeur d'Application, avant toute migration (installateur web,
        // `php artisan migrate` sur base neuve). La table app_settings n'existe pas encore : sans
        // repli, « no such table » empêchait toute installation neuve.
        $container = new \Tests\Support\TestContainer();
        kintai_test_database();

        (new LicenseServiceProvider($container))->register();

        $features = $container->make(FeatureManager::class);
        $this->assertTrue($features->isEnabled('daily-report'), 'Repli sur config/license.php attendu.');
    }

    public function testARawPdoErrorIsAlsoTolerated(): void
    {
        $container = new \Tests\Support\TestContainer();
        self::failingConnections(new \PDOException('no such table: app_settings'));

        (new LicenseServiceProvider($container))->register();

        $this->assertInstanceOf(FeatureManager::class, $container->make(FeatureManager::class));
    }

    public function testNonDatabaseErrorsAreNotSwallowed(): void
    {
        // Seules les erreurs de base sont tolérées : un vrai bogue doit rester visible.
        $container = new \Tests\Support\TestContainer();
        self::failingConnections(new \LogicException('bogue'));

        $this->expectException(\LogicException::class);
        (new LicenseServiceProvider($container))->register();
    }

    public function testHardcodedDefaultsMatchTheShippedLicenseConfig(): void
    {
        $config = require BASE_PATH . '/config/license.php';

        $expected = $config['enabled_features'];
        $actual = LicenseServiceProvider::DEFAULT_FEATURES;
        sort($expected);
        sort($actual);

        // Le fallback ne joue que si config/license.php est absent : s'il dérive, un
        // bundle officiel (ex. team-directory, notebook) disparaît silencieusement.
        $this->assertSame($expected, $actual);
    }
}
