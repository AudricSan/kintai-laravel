<?php

namespace Tests\Feature\License;

use App\License\LicenseState;
use App\License\LicenseStatus;
use App\Models\Store;
use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Settings\AppSettings;
use App\System\CoreSystemAlerts;
use App\System\SystemAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\SignsLicenseTokens;
use Tests\TestCase;

class LicenseScreenAndAlertsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use SignsLicenseTokens;

    private const BASE = 'https://lic.test/public/api/v1';

    private const KEY = 'KEY-SECRET-1234-ABCD';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        config(['kintai.license.api_url' => self::BASE, 'kintai.license.api_key' => 'product-key', 'kintai.license.issuer' => null]);
        $this->useTestKey();
        $this->seedRoles();
        $this->owner = $this->owner();
    }

    private function fake(string $endpoint, mixed $response): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([self::BASE.'/'.$endpoint => $response]);
    }

    /** @param  array<string, mixed>  $claims */
    private function storeLicense(array $claims = []): void
    {
        $settings = $this->app->make(AppSettings::class);
        $settings->setMany(['license.key' => self::KEY, 'license.token' => $this->token($claims), 'license.validated_at' => now()->toIso8601String()]);
    }

    private function state(): LicenseState
    {
        return $this->app->make(LicenseState::class);
    }

    // -- Écran ---------------------------------------------------------------------------------------------------

    public function test_the_owner_sees_the_screen_with_the_buy_link_opened_safely(): void
    {
        $this->actingAs($this->owner);
        $this->storeLicense();

        $this->get(route('license.index'))->assertOk()
            ->assertSee('data-test="license-state"', false)
            ->assertSee('max_stores')
            ->assertSee('href="https://lic.test/public/buy/kintai"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener"', false)
            ->assertDontSee(self::KEY);

        $this->get(route('dashboard'))->assertSee(route('license.index'));
    }

    public function test_others_are_refused(): void
    {
        $this->actingAs($this->managerOf(Store::factory()->create()));

        $this->get(route('license.index'))->assertForbidden();
        Livewire::test('pages::admin.license')->assertForbidden();

        $this->actingAs($this->employeeOf(Store::factory()->create()));
        $this->get(route('license.index'))->assertForbidden();
        $this->assertStringNotContainsString(route('license.index'), (string) $this->get(route('dashboard'))->getContent());
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('license.index'))->assertRedirect(route('login'));
    }

    public function test_activating_from_the_screen_never_leaks_the_key(): void
    {
        $this->actingAs($this->owner);
        $this->fake('activate', Http::response(['valid' => true, 'status' => 'active', 'type' => 'subscription', 'max_activations' => 2, 'active_activations' => 1, 'license_token' => $this->token()]));

        $component = Livewire::test('pages::admin.license')
            ->set('licenseKey', self::KEY)
            ->call('activate')
            ->assertSet('licenseKey', '')
            ->assertSet('error', '');

        $this->assertStringNotContainsString(self::KEY, $component->html());
        $this->assertStringNotContainsString(self::KEY, json_encode($component->snapshot));
        $this->assertSame(LicenseStatus::ACTIVE, $this->state()->state());
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('activity_entries')->get()));
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('app_settings')->get()));
    }

    public function test_a_refused_activation_shows_a_message_and_forgets_the_typed_key(): void
    {
        $this->actingAs($this->owner);
        $this->fake('activate', Http::response(['valid' => false, 'error' => 'max_activations_reached', 'max_activations' => 3]));

        $component = Livewire::test('pages::admin.license')->set('licenseKey', self::KEY)->call('activate');

        $this->assertStringContainsString('All the seats of this license are used (3)', $component->get('error'));
        $this->assertStringNotContainsString(self::KEY, $component->html());
        $this->assertSame(LicenseStatus::FREE, $this->state()->state());
    }

    public function test_an_empty_key_is_a_validation_error_without_any_request(): void
    {
        $this->actingAs($this->owner);
        Http::preventStrayRequests();

        Livewire::test('pages::admin.license')->set('licenseKey', '  ')->call('activate')->assertHasErrors('licenseKey');
    }

    public function test_check_and_deactivate_from_the_screen(): void
    {
        $this->actingAs($this->owner);
        $this->storeLicense();

        $this->fake('validate', Http::response('', 502));
        Livewire::test('pages::admin.license')->call('check')->assertSet('error', 'The license server could not be reached. Try again later.');
        $this->assertSame(LicenseStatus::GRACE, $this->state()->state());

        $this->fake('deactivate', Http::response(['valid' => true]));
        Livewire::test('pages::admin.license')->call('deactivate')->assertSet('error', '');
        $this->assertSame(LicenseStatus::FREE, $this->state()->state());
        $this->assertFalse($this->state()->hasKey());
    }

    public function test_actions_of_a_non_owner_are_forbidden(): void
    {
        $this->actingAs($this->managerOf(Store::factory()->create()));
        Http::preventStrayRequests();

        Livewire::test('pages::admin.license')->assertForbidden();
        $this->assertFalse($this->state()->hasKey());
    }

    // -- Alertes -------------------------------------------------------------------------------------------------

    public function test_the_alerts_are_registered_with_their_reference_ids_and_types(): void
    {
        $alerts = $this->app->make(SystemAlerts::class);
        $this->assertContains('license_problem', $alerts->types());
        $this->assertContains('license_expiring', $alerts->types());

        foreach (['license_problem', 'license_expiring'] as $type) {
            $notification = $this->app->make(NotificationTypeRegistry::class)->get($type);
            $this->assertTrue($notification->ownerOnly);
            $this->assertTrue($notification->mail);
            $this->assertTrue($notification->push);
        }
    }

    public function test_no_alert_without_a_license_or_with_a_healthy_one(): void
    {
        Http::preventStrayRequests();
        $alerts = $this->app->make(CoreSystemAlerts::class);

        $this->assertNull($alerts->licenseProblem());
        $this->assertNull($alerts->licenseExpiring());

        $this->storeLicense();
        $this->assertNull($alerts->licenseProblem());
        $this->assertNull($alerts->licenseExpiring());

        // Hors ligne avec un jeton encore valable : pas de problème.
        $this->app->make(AppSettings::class)->set('license.last_error', 'unreachable');
        $this->assertNull($alerts->licenseProblem());
        Http::assertNothingSent();
    }

    public function test_a_problem_alert_for_each_refusal_and_for_an_offline_expired_token(): void
    {
        Http::preventStrayRequests();
        $settings = $this->app->make(AppSettings::class);
        $alerts = $this->app->make(CoreSystemAlerts::class);

        foreach (['license_expired', 'license_revoked', 'license_suspended', 'not_activated'] as $refusal) {
            $this->storeLicense();
            $settings->setMany(['license.token' => '', 'license.refusal' => $refusal]);

            $alert = $alerts->licenseProblem();
            $this->assertNotNull($alert, $refusal);
            $this->assertSame(CoreSystemAlerts::BODIES['license_problem'], $alert->body);
            $this->assertSame(route('license.index', [], false), $alert->link);
            $this->assertStringNotContainsString(self::KEY, json_encode($alert));
        }

        $settings->setMany(['license.refusal' => '', 'license.last_error' => 'unreachable']);
        $this->storeLicense();
        $settings->set('license.last_error', 'unreachable');
        Carbon::setTestNow(now()->addDays(20));
        $this->assertNotNull($alerts->licenseProblem());
    }

    public function test_the_expiring_alert_fires_within_fourteen_days_only(): void
    {
        $alerts = $this->app->make(CoreSystemAlerts::class);

        $this->storeLicense(['expires_at' => now()->addDays(20)->toIso8601String()]);
        $this->assertNull($alerts->licenseExpiring());

        $this->storeLicense(['expires_at' => now()->addDays(10)->toIso8601String()]);
        $alert = $alerts->licenseExpiring();
        $this->assertNotNull($alert);
        $this->assertSame('2026-11-15', $alert->key);
        $this->assertSame(10, $alert->params['days']);
        $this->assertSame(CoreSystemAlerts::BODIES['license_expiring'], $alert->body);

        // Sans date de fin : jamais d'alerte.
        $this->storeLicense(['expires_at' => null]);
        $this->assertNull($alerts->licenseExpiring());
    }

    public function test_the_owner_is_notified_once_for_a_problem(): void
    {
        Notification::fake();
        $this->storeLicense();
        $this->app->make(AppSettings::class)->setMany(['license.token' => '', 'license.refusal' => 'license_revoked']);

        $this->assertContains('license_problem', $this->app->make(SystemAlerts::class)->run());
        Notification::assertSentTo($this->owner, KintaiNotification::class);
        $this->assertNotContains('license_problem', $this->app->make(SystemAlerts::class)->run());
    }

    public function test_texts_are_translated_in_french(): void
    {
        $french = json_decode((string) file_get_contents(lang_path('fr.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (CoreSystemAlerts::LICENSE_PROBLEMS as $label) {
            $this->assertArrayHasKey($label, $french);
        }

        foreach (['license_problem', 'license_expiring'] as $type) {
            $this->assertArrayHasKey(CoreSystemAlerts::BODIES[$type], $french);
        }
    }
}
