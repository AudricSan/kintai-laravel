<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Container;
use kintai\Core\FeatureManager;
use kintai\Core\LicenseServiceProvider;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use PHPUnit\Framework\TestCase;

// BASE_PATH est requis par LicenseServiceProvider (fallback vers config/license.php).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

final class LicenseServiceProviderTest extends TestCase
{
    public function testUsesEnabledBundlesFromAppSettingsWhenPresent(): void
    {
        $container = new Container();
        $appSettings = $this->createMock(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->with(LicenseServiceProvider::SETTINGS_KEY)
            ->willReturn('["daily-report","store-photos"]');
        $container->instance(AppSettingsRepositoryInterface::class, $appSettings);

        (new LicenseServiceProvider($container))->register();

        $features = $container->make(FeatureManager::class);
        $this->assertTrue($features->isEnabled('daily-report'));
        $this->assertTrue($features->isEnabled('store-photos'));
        $this->assertFalse($features->isEnabled('messaging'));
    }

    public function testFallsBackToConfigFileWhenNoAppSettingsValueStored(): void
    {
        $container = new Container();
        $appSettings = $this->createMock(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->with(LicenseServiceProvider::SETTINGS_KEY)->willReturn(null);
        $container->instance(AppSettingsRepositoryInterface::class, $appSettings);

        (new LicenseServiceProvider($container))->register();

        $features = $container->make(FeatureManager::class);
        // config/license.php (celui du dépôt de test) active daily-report + messaging par défaut.
        $this->assertTrue($features->isEnabled('daily-report'));
        $this->assertTrue($features->isEnabled('messaging'));
    }

    public function testANotYetMigratedDatabaseFallsBackToTheConfigFileInsteadOfCrashing(): void
    {
        // Ce fournisseur tourne dans le constructeur d'Application, avant toute migration (installateur web,
        // `php scripts/db-migrate.php` sur base neuve). La table app_settings n'existe pas encore : sans
        // repli, « no such table » empêchait toute installation neuve.
        $container = new Container();
        $appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->willThrowException(new \Illuminate\Database\QueryException(
            'sqlite',
            'select * from "app_settings" where "app_settings"."key" = ? limit 1',
            [LicenseServiceProvider::SETTINGS_KEY],
            new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: app_settings'),
        ));
        $container->instance(AppSettingsRepositoryInterface::class, $appSettings);

        (new LicenseServiceProvider($container))->register();

        $features = $container->make(FeatureManager::class);
        $this->assertTrue($features->isEnabled('daily-report'), 'Repli sur config/license.php attendu.');
    }

    public function testARawPdoErrorIsAlsoTolerated(): void
    {
        $container = new Container();
        $appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->willThrowException(new \PDOException('no such table: app_settings'));
        $container->instance(AppSettingsRepositoryInterface::class, $appSettings);

        (new LicenseServiceProvider($container))->register();

        $this->assertInstanceOf(FeatureManager::class, $container->make(FeatureManager::class));
    }

    public function testNonDatabaseErrorsAreNotSwallowed(): void
    {
        // Seules les erreurs de base sont tolérées : un vrai bogue doit rester visible.
        $container = new Container();
        $appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->willThrowException(new \LogicException('bogue'));
        $container->instance(AppSettingsRepositoryInterface::class, $appSettings);

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
