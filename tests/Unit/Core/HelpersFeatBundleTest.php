<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\TestCase;

/**
 * Régression : désactiver ou désinstaller un bundle (ex. shift-swap) ne doit
 * jamais faire planter les vues qui génèrent des route_url() vers ses routes
 * (sidebar, bottom nav, dashboards). bundle_enabled()/feat_bundle() sont le
 * garde-fou utilisé par ces vues ; ce test vérifie qu'ils reflètent l'état
 * réel après boot (BundleManager::isActive() — découvert ET activé), pas
 * seulement le réglage stocké (FeatureManager::isEnabled()), qui peut rester
 * "activé" pour un bundle disparu du disque (voir CHANGELOG : c'est
 * exactement ce qui a cassé la nav en production lors de l'extraction du
 * bundle DailyReport hors du monorepo).
 */
final class HelpersFeatBundleTest extends TestCase
{
    protected function setUp(): void
    {
        Container::getInstance()->instance(
            BundleManager::class,
            FakeBundleManagerFactory::withActiveSlugs(['daily-report', 'messaging', 'timeoff']),
        );
    }

    protected function tearDown(): void
    {
        // Réinitialise le singleton Container pour ne pas propager le
        // BundleManager injecté ici vers d'autres tests.
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $instance->setValue(null, null);
    }

    public function testBundleEnabledReflectsBundleManagerState(): void
    {
        $this->assertTrue(bundle_enabled('timeoff'));
        $this->assertFalse(bundle_enabled('shift-swap'));
    }

    public function testFeatBundleAllowsSlugsWithNoOwningBundle(): void
    {
        // Un slug store_features sans bundle associé (ou null) n'est jamais bloqué ici :
        // c'est une fonctionnalité Core, toujours disponible.
        $this->assertTrue(feat_bundle(null));
        $this->assertTrue(feat_bundle('shifts'));
    }
}
