<?php

namespace Tests\Feature\License;

use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use App\System\CoreSystemAlerts;
use App\System\SystemAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\SignsLicenseTokens;
use Tests\TestCase;

/**
 * Alerte `license_quota` : plafond de magasins ou d'employés atteint, jamais d'appel réseau.
 */
class LicenseQuotaAlertTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use SignsLicenseTokens;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.license.issuer' => null]);
        $this->useTestKey();
        $this->seedRoles();
    }

    /** @param  array<string, int|null>  $limits */
    private function limits(array $limits): void
    {
        $this->app->make(AppSettings::class)->setMany(['license.key' => 'KEY', 'license.token' => $this->token(['limits' => $limits])]);
    }

    public function test_it_is_registered_and_owner_only(): void
    {
        $this->assertContains('license_quota', $this->app->make(SystemAlerts::class)->types());

        $type = $this->app->make(NotificationTypeRegistry::class)->get('license_quota');
        $this->assertTrue($type->ownerOnly);
        $this->assertTrue($type->mail);
        $this->assertTrue($type->push);
    }

    public function test_no_alert_below_the_limits_or_when_unlimited(): void
    {
        $alerts = $this->app->make(CoreSystemAlerts::class);
        $this->owner();
        Store::factory()->create();

        $this->limits(['max_stores' => 3, 'max_employees' => 50]);
        $this->assertNull($alerts->licenseQuota());

        $this->limits(['max_stores' => null, 'max_employees' => null]);
        $this->assertNull($alerts->licenseQuota());
    }

    public function test_alert_when_the_store_limit_is_reached(): void
    {
        $this->owner();
        Store::factory()->count(2)->create();
        $this->limits(['max_stores' => 2, 'max_employees' => 50]);

        $alert = $this->app->make(CoreSystemAlerts::class)->licenseQuota();

        $this->assertNotNull($alert);
        $this->assertSame('stores:2', $alert->key);
        $this->assertSame(CoreSystemAlerts::BODIES['license_quota'], $alert->body);
        $this->assertSame('2 stores', $alert->params['quotas']);
    }

    public function test_alert_when_both_limits_are_reached_and_key_changes_with_the_limit(): void
    {
        $this->owner();
        Store::factory()->create();
        $users = User::query()->count();
        $this->limits(['max_stores' => 1, 'max_employees' => $users]);

        $alert = $this->app->make(CoreSystemAlerts::class)->licenseQuota();
        $this->assertSame('stores:1,employees:'.$users, $alert?->key);

        $this->limits(['max_stores' => 1, 'max_employees' => $users + 5]);
        $this->assertSame('stores:1', $this->app->make(CoreSystemAlerts::class)->licenseQuota()?->key);
    }

    public function test_texts_are_translated_in_french(): void
    {
        $french = json_decode((string) file_get_contents(lang_path('fr.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey(CoreSystemAlerts::BODIES['license_quota'], $french);
        $this->assertArrayHasKey('License limit reached', $french);
        $this->assertArrayHasKey(':max stores', $french);
        $this->assertArrayHasKey(':max employees', $french);
    }
}
