<?php

namespace Tests\Feature\License;

use App\Actions\Bundles\EnableBundle;
use App\Bundles\BundleException;
use App\Bundles\BundleManager;
use App\License\LicenseState;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\SignsLicenseTokens;
use Tests\TestCase;

/**
 * Bundles accordés par la licence : claim `limits.bundles`, palier gratuit, point central du BundleManager.
 */
class LicenseBundlesTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use SignsLicenseTokens;

    private const FREE = ['timeoff', 'timeclock', 'shift-swap', 'team-directory'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.license.issuer' => null, 'kintai.license.free_bundles' => self::FREE]);
        $this->useTestKey();
        $this->seedRoles();
    }

    private function state(): LicenseState
    {
        return $this->app->make(LicenseState::class);
    }

    /** @param  array<string, mixed>  $limits */
    private function activeLicense(array $limits): void
    {
        $this->app->make(AppSettings::class)->setMany(['license.key' => 'KEY', 'license.token' => $this->token(['limits' => $limits])]);
    }

    public function test_without_a_license_only_the_free_bundles_are_allowed(): void
    {
        $this->assertSame(self::FREE, $this->state()->bundles());
        $this->assertTrue($this->state()->allowsBundle('timeoff'));
        $this->assertFalse($this->state()->allowsBundle('messaging'));
    }

    public function test_a_token_without_the_claim_allows_every_bundle(): void
    {
        $this->activeLicense(['max_stores' => 5, 'max_employees' => 50]);

        $this->assertNull($this->state()->bundles());
        $this->assertTrue($this->state()->allowsBundle('messaging'));
    }

    public function test_a_token_with_a_list_allows_only_these_bundles(): void
    {
        $this->activeLicense(['bundles' => ['timeoff', 'messaging']]);

        $this->assertSame(['timeoff', 'messaging'], $this->state()->bundles());
        $this->assertTrue($this->state()->allowsBundle('messaging'));
        $this->assertFalse($this->state()->allowsBundle('notebook'));
    }

    public function test_a_wildcard_or_a_comma_string_is_understood(): void
    {
        $this->activeLicense(['bundles' => '*']);
        $this->assertNull($this->state()->bundles());

        $this->activeLicense(['bundles' => 'timeoff, notebook']);
        $this->assertSame(['timeoff', 'notebook'], $this->state()->bundles());

        $this->activeLicense(['bundles' => ['timeoff', '*']]);
        $this->assertNull($this->state()->bundles());
    }

    public function test_an_unreadable_claim_allows_nothing(): void
    {
        $this->activeLicense(['bundles' => 12]);

        $this->assertSame([], $this->state()->bundles());
        $this->assertFalse($this->state()->allowsBundle('timeoff'));
    }

    public function test_a_refused_license_falls_back_to_the_free_bundles(): void
    {
        $this->app->make(AppSettings::class)->setMany(['license.key' => 'KEY', 'license.refusal' => 'license_revoked']);

        $this->assertSame(self::FREE, $this->state()->bundles());
    }

    public function test_the_bundle_manager_never_enables_a_bundle_outside_the_license(): void
    {
        $manager = $this->app->make(BundleManager::class);

        $this->assertTrue($manager->enable('timeoff'));
        $this->assertTrue($manager->isEnabled('timeoff'));

        try {
            $manager->enable('messaging');
            $this->fail('A bundle outside the license cannot be enabled.');
        } catch (BundleException) {
            $this->assertFalse($manager->isEnabled('messaging'));
        }

        $this->expectException(ValidationException::class);
        $this->app->make(EnableBundle::class)->handle($this->owner(), 'messaging');
    }

    public function test_a_selected_bundle_is_hidden_when_the_license_stops_covering_it_and_comes_back_with_it(): void
    {
        $manager = $this->app->make(BundleManager::class);
        $this->activeLicense(['bundles' => '*']);
        $manager->enable('messaging');
        $this->assertTrue($manager->isEnabled('messaging'));
        $this->assertContains('messaging', $manager->enabledSlugs());

        $this->app->make(AppSettings::class)->setMany(['license.token' => '', 'license.key' => '']);

        $this->assertFalse($manager->isEnabled('messaging'));
        $this->assertNotContains('messaging', $manager->enabledSlugs());
        // Le choix de l'Owner (donc les données du bundle) est conservé.
        $this->assertContains('messaging', $manager->selected());

        $this->activeLicense(['bundles' => ['messaging']]);
        $this->assertTrue($manager->isEnabled('messaging'));
    }
}
