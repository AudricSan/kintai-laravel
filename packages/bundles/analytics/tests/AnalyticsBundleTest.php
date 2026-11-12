<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Bundles\BundleManager;
use App\Sales\NullDailySalesProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\Analytics\AnalyticsServiceProvider;
use Kintai\Contracts\Contract;
use Kintai\Contracts\Sales\DailySalesProvider;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Identité du bundle, contrat exigé, et valeur par défaut du Core pour les ventes.
 */
class AnalyticsBundleTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    public function test_the_bundle_is_installed_but_off_until_the_owner_enables_it(): void
    {
        $bundle = app(BundleManager::class)->find('analytics');

        $this->assertNotNull($bundle);
        $this->assertSame(AnalyticsServiceProvider::class, $bundle::class);
        $this->assertFalse($bundle->isEnabled());

        $this->enableBundle('analytics');
        $this->assertTrue(app(BundleManager::class)->find('analytics')?->isEnabled());
    }

    public function test_the_required_contract_is_satisfied_and_needs_the_sales_provider(): void
    {
        $provider = new AnalyticsServiceProvider($this->app);

        $this->assertTrue(Contract::satisfies($provider->requiredContract()));
        $this->assertFalse(Contract::satisfies($provider->requiredContract(), '1.5.0'));
    }

    public function test_the_core_binds_an_empty_sales_provider_by_default(): void
    {
        $provider = app(DailySalesProvider::class);

        $this->assertInstanceOf(NullDailySalesProvider::class, $provider);
        $this->assertFalse($provider->available());
        $this->assertSame([], $provider->dailySales(1, now(), now()));
    }
}
