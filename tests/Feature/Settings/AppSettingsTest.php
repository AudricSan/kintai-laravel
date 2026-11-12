<?php

namespace Tests\Feature\Settings;

use App\Actions\Activity\PurgeActivityEntries;
use App\Actions\Notifications\PurgeNotifications;
use App\Models\AppSetting;
use App\Push\VapidKeys;
use App\Settings\AppSettings;
use App\Settings\CoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Kintai\Contracts\Settings\SettingDefinition;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class AppSettingsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    /** Un service neuf, comme au début d'une autre requête (même cache applicatif). */
    private function freshSettings(): AppSettings
    {
        $settings = new AppSettings;
        CoreSettings::registerIn($settings);

        return $settings;
    }

    public function test_defaults_come_from_the_configuration_when_nothing_is_stored(): void
    {
        $settings = $this->settings();

        $this->assertSame(300, $settings->apiRateLimit());
        $this->assertSame(180, $settings->activityRetentionDays());
        $this->assertSame(90, $settings->notificationsRetentionDays());
        $this->assertFalse($settings->maintenanceEnabled());
        $this->assertSame('', $settings->subtitle());
        $this->assertSame('auto', $settings->themeDarkMode());
        $this->assertSame('#ff9f4a', $settings->themeColor('primary'));
        $this->assertSame('', $settings->themeColorDark('primary'));
        $this->assertFalse($settings->isStored('api.rate_limit'));
        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_the_configuration_is_the_fallback_and_the_database_wins(): void
    {
        config(['kintai.api.rate_limit' => 42, 'kintai.webpush.public_key' => 'from-env']);
        $settings = $this->freshSettings();

        $this->assertSame(42, $settings->apiRateLimit());
        $this->assertSame('from-env', $settings->webPushPublicKey());

        $settings->set('api.rate_limit', 10);
        $settings->set('webpush.public_key', 'from-db');

        $this->assertSame(10, $settings->apiRateLimit());
        $this->assertSame('from-db', $settings->webPushPublicKey());
        $this->assertTrue($settings->isStored('api.rate_limit'));

        $settings->forget('api.rate_limit', 'webpush.public_key');

        $this->assertSame(42, $settings->apiRateLimit());
        $this->assertSame('from-env', $settings->webPushPublicKey());
    }

    public function test_values_are_typed_trimmed_and_bounded_on_write(): void
    {
        $settings = $this->settings();

        $settings->setMany([
            'organization.subtitle' => "  Acme  \n",
            'maintenance.enabled' => '1',
            'api.rate_limit' => '999999',
            'activity.retention_days' => -5,
            'notifications.retention_days' => 1,
        ]);

        $this->assertSame('Acme', $settings->subtitle());
        $this->assertTrue($settings->maintenanceEnabled());
        $this->assertSame(100000, $settings->apiRateLimit());
        $this->assertSame(0, $settings->activityRetentionDays());
        $this->assertSame(7, $settings->notificationsRetentionDays());

        $settings->set('notifications.retention_days', 99999);
        $this->assertSame(3650, $settings->notificationsRetentionDays());

        $settings->set('maintenance.enabled', false);
        $this->assertFalse($settings->maintenanceEnabled());
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->get('nope.nothing');
    }

    public function test_secrets_are_encrypted_in_the_database(): void
    {
        $settings = $this->settings();

        $settings->set('webpush.private_key', 'super-secret-private-key');

        $raw = AppSetting::query()->findOrFail('webpush.private_key')->value;
        $this->assertNotSame('super-secret-private-key', $raw);
        $this->assertStringNotContainsString('super-secret', (string) json_encode($raw));
        $this->assertSame('super-secret-private-key', Crypt::decryptString($raw));
        $this->assertSame('super-secret-private-key', $settings->webPushPrivateKey());
        $this->assertSame('super-secret-private-key', $this->freshSettings()->webPushPrivateKey());
    }

    public function test_an_undecryptable_secret_falls_back_to_the_configuration(): void
    {
        config(['kintai.webpush.private_key' => 'env-private']);
        AppSetting::query()->create(['key' => 'webpush.private_key', 'value' => 'not-an-encrypted-payload']);

        $this->assertSame('env-private', $this->freshSettings()->webPushPrivateKey());
    }

    public function test_the_cache_is_invalidated_on_write(): void
    {
        $first = $this->freshSettings();
        $this->assertSame(300, $first->apiRateLimit());

        // Une écriture hors du service ne vide pas le cache : une autre requête voit encore l'ancienne valeur.
        AppSetting::query()->create(['key' => 'api.rate_limit', 'value' => 5]);
        $this->assertSame(300, $this->freshSettings()->apiRateLimit());

        // Une écriture par le service l'invalide : toute requête suivante voit la nouvelle valeur.
        $first->set('api.rate_limit', 7);
        $this->assertSame(7, $this->freshSettings()->apiRateLimit());
        $this->assertSame(7, $first->apiRateLimit());
    }

    public function test_bundles_can_register_their_own_definitions(): void
    {
        $settings = $this->settings();
        $settings->register(
            new SettingDefinition('timeoff.max_days', SettingDefinition::TYPE_INT, 25, 1, 60),
            new SettingDefinition('timeoff.label', default: 'Leave'),
        );

        $this->assertSame(25, $settings->int('timeoff.max_days'));
        $this->assertSame('Leave', $settings->string('timeoff.label'));

        $settings->set('timeoff.max_days', 500);

        $this->assertSame(60, $settings->int('timeoff.max_days'));
    }

    public function test_the_existing_readers_use_the_settings(): void
    {
        $this->assertSame(90, PurgeNotifications::retentionDays());
        $this->assertSame(180, PurgeActivityEntries::retentionDays());

        $this->settings()->setMany(['notifications.retention_days' => 30, 'activity.retention_days' => 0]);

        $this->assertSame(30, PurgeNotifications::retentionDays());
        $this->assertSame(0, PurgeActivityEntries::retentionDays());
    }

    public function test_vapid_keys_are_read_from_the_settings_then_the_environment(): void
    {
        config(['kintai.webpush.public_key' => 'env-public', 'kintai.webpush.private_key' => 'env-private']);
        $keys = $this->app->make(VapidKeys::class);

        $this->assertSame('env-public', $keys->publicKey());

        $this->settings()->setMany(['webpush.public_key' => 'db-public', 'webpush.private_key' => 'db-private']);

        $this->assertSame('db-public', $keys->publicKey());
        $this->assertSame('db-private', $keys->privateKey());
        $this->assertTrue($keys->isConfigured());
    }

    public function test_a_missing_table_does_not_break_reads(): void
    {
        Schema::drop('app_settings');

        $this->assertSame(300, $this->freshSettings()->apiRateLimit());
    }
}
