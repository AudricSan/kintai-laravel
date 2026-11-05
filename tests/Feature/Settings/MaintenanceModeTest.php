<?php

namespace Tests\Feature\Settings;

use App\Models\IcalToken;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private Store $store;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->employee = $this->employeeOf($this->store);
    }

    private function enableMaintenance(string $message = 'Back at 6pm.'): void
    {
        $this->app->make(AppSettings::class)->setMany(['maintenance.enabled' => true, 'maintenance.message' => $message]);
    }

    public function test_nothing_changes_while_maintenance_is_off(): void
    {
        $this->actingAs($this->employee)->get(route('notifications.index'))->assertOk();
    }

    public function test_an_employee_gets_a_503_with_the_message_and_retry_after(): void
    {
        $this->enableMaintenance();

        $response = $this->actingAs($this->employee)->get(route('notifications.index'));

        $response->assertStatus(503)->assertSee('Back at 6pm.');
        $this->assertSame('300', $response->headers->get('Retry-After'));
    }

    public function test_a_manager_is_blocked_too_and_the_message_is_escaped(): void
    {
        $this->enableMaintenance('<script>alert(1)</script>');

        $this->actingAs($this->managerOf($this->store))->get(route('employees.index'))
            ->assertStatus(503)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_a_visitor_gets_a_503_and_a_default_message_when_none_is_set(): void
    {
        $this->enableMaintenance('');

        $this->get('/')->assertStatus(503);
        $this->get(route('notifications.unsubscribe'))->assertStatus(503)->assertSee('The application is under maintenance. Please try again later.');
    }

    public function test_the_support_email_is_shown_on_the_maintenance_page(): void
    {
        $this->enableMaintenance();
        $this->app->make(AppSettings::class)->set('organization.support_email', 'help@example.com');

        $this->get(route('notifications.unsubscribe'))->assertStatus(503)->assertSee('help@example.com');
    }

    public function test_the_owner_keeps_access(): void
    {
        $this->enableMaintenance();

        $this->actingAs($this->owner())->get(route('settings.app'))->assertOk();
    }

    public function test_the_owner_can_turn_maintenance_off_while_it_is_on(): void
    {
        $this->enableMaintenance();

        Livewire::actingAs($this->owner())->test('pages::admin.settings')
            ->set('maintenance_enabled', false)
            ->call('saveMaintenance')
            ->assertHasNoErrors();

        $this->actingAs($this->employee)->get(route('notifications.index'))->assertOk();
    }

    public function test_the_login_page_and_its_actions_stay_reachable(): void
    {
        $this->enableMaintenance();

        $this->get(route('login'))->assertOk();
        $this->get(route('two-factor.login'))->assertStatus(302);
        $this->get('/passkeys/login/options')->assertSuccessful();
        $this->post(route('login.store'), ['login' => 'nobody@example.com', 'password' => 'wrong'])->assertStatus(302);
    }

    public function test_logging_in_as_the_owner_works_during_maintenance(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['password' => bcrypt('owner-password')])->save();
        $this->enableMaintenance();

        $this->post(route('login.store'), ['login' => $owner->email, 'password' => 'owner-password'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);

        $this->get(route('settings.app'))->assertOk();
    }

    public function test_logging_out_stays_possible(): void
    {
        $this->enableMaintenance();

        $this->actingAs($this->employee)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_service_worker_the_manifest_and_the_ical_feed_stay_reachable(): void
    {
        IcalToken::factory()->forToken(self::TOKEN)->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id]);
        $this->enableMaintenance();

        $this->get(route('pwa.sw'))->assertOk();
        $this->get(route('pwa.manifest'))->assertOk();
        $this->get('/ical/'.self::TOKEN.'/'.$this->store->id.'/shifts.ics')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_a_json_web_request_gets_a_json_503(): void
    {
        $this->enableMaintenance('Back at 6pm.');

        $this->actingAs($this->employee)->getJson(route('notifications.index'))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Back at 6pm.');
    }
}
