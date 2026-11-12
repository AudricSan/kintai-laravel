<?php

namespace Kintai\Bundles\GoogleCalendar\Tests;

use App\Authorization\Authorizer;
use App\Models\ActivityEntry;
use App\Models\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarSettings;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Contracts\Settings\Settings;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/GoogleCalendarFixtures.php';

/**
 * Réglages de l'instance : réservés à l'Owner, secret client chiffré et jamais renvoyé au navigateur, adresse de
 * retour OAuth, test des identifiants, journal sans identifiant ni secret.
 */
class SettingsTest extends TestCase
{
    use GoogleCalendarFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootGoogleCalendar();
    }

    private function settings(): GoogleCalendarSettings
    {
        return app(GoogleCalendarSettings::class);
    }

    // -- Autorisation ---------------------------------------------------------------------------------------------------

    public function test_only_the_owner_reaches_the_settings(): void
    {
        $this->get('/google-calendar/settings')->assertRedirect('/login');
        $this->actingAs($this->employee())->get('/google-calendar/settings')->assertForbidden();
        $this->actingAs($this->managerOf($this->store))->get('/google-calendar/settings')->assertForbidden();
        $this->actingAs($this->owner())->get('/google-calendar/settings')->assertOk()->assertSee('Google Cloud project');
    }

    public function test_the_component_rechecks_the_owner_on_every_action(): void
    {
        $manager = $this->managerOf($this->store);

        Livewire::actingAs($manager)->test('google-calendar.settings')->assertForbidden();

        $owner = $this->owner();
        $component = Livewire::actingAs($owner)->test('google-calendar.settings')->set('clientId', 'abc')->set('clientSecret', 'def');
        // L'Owner perd son rôle entre l'ouverture de la page et l'envoi.
        RoleAssignment::query()->where('user_id', $owner->id)->delete();
        app(Authorizer::class)->forget($owner);
        $component->call('save')->assertForbidden();

        $this->assertSame('', $this->settings()->clientId());
        $this->assertFalse($this->settings()->hasSecret());
    }

    // -- Enregistrement -------------------------------------------------------------------------------------------------

    public function test_the_owner_saves_the_credentials_and_activates_the_feature(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)->test('google-calendar.settings')
            ->set('enabled', true)->set('clientId', ' my-client-id.apps.googleusercontent.com'.' ')
            ->set('clientSecret', 'GOCSPX-very-secret')->call('save')->assertHasNoErrors()
            ->assertSet('clientSecret', '');

        $this->assertTrue($this->settings()->isAvailable());
        $this->assertSame('my-client-id.apps.googleusercontent.com', $this->settings()->clientId());
        $this->assertSame('GOCSPX-very-secret', $this->settings()->clientSecret());
    }

    public function test_the_feature_is_not_available_without_both_credentials(): void
    {
        $this->settings()->save(true, 'only-an-id', null);
        $this->assertFalse($this->settings()->isAvailable());

        $this->settings()->save(false, 'id', 'secret');
        $this->assertFalse($this->settings()->isAvailable());

        $this->settings()->save(true, '', null);
        $this->assertFalse($this->settings()->isAvailable());
    }

    public function test_the_secret_is_encrypted_in_the_database_and_never_sent_back(): void
    {
        $owner = $this->owner();
        $this->settings()->save(true, 'client-id-123', 'GOCSPX-never-shown');

        $stored = (string) DB::table('app_settings')->where('key', GoogleCalendarSettings::CLIENT_SECRET)->value('value');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('GOCSPX-never-shown', $stored);

        $page = $this->actingAs($owner)->get('/google-calendar/settings');
        $page->assertOk()->assertDontSee('GOCSPX-never-shown')->assertSee('client-id-123')->assertSee('Secret saved (leave empty to keep it)');

        $component = Livewire::actingAs($owner)->test('google-calendar.settings')->assertSet('clientSecret', '')->assertDontSee('GOCSPX-never-shown');
        $this->assertStringNotContainsString('GOCSPX-never-shown', (string) json_encode($component->snapshot));
    }

    public function test_an_empty_secret_keeps_the_saved_one(): void
    {
        $this->settings()->save(true, 'client-id-123', 'GOCSPX-keep-me');

        Livewire::actingAs($this->owner())->test('google-calendar.settings')
            ->set('clientId', 'other-id')->set('clientSecret', '   ')->call('save')->assertHasNoErrors();

        $this->assertSame('other-id', $this->settings()->clientId());
        $this->assertSame('GOCSPX-keep-me', $this->settings()->clientSecret());
    }

    public function test_the_fields_are_validated(): void
    {
        Livewire::actingAs($this->owner())->test('google-calendar.settings')
            ->set('clientId', 'has a space')->call('save')->assertHasErrors('clientId')
            ->set('clientId', str_repeat('a', 256))->call('save')->assertHasErrors('clientId')
            ->set('clientId', 'ok')->set('clientSecret', str_repeat('s', 256))->call('save')->assertHasErrors('clientSecret');
    }

    public function test_the_log_records_neither_the_secret_nor_the_client_id(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)->test('google-calendar.settings')
            ->set('enabled', true)->set('clientId', 'client-id-leak')->set('clientSecret', 'GOCSPX-leak')->call('save');

        $entry = ActivityEntry::query()->where('action', 'google_calendar.settings_updated')->sole();
        $this->assertSame($owner->id, $entry->actor_id);
        $log = (string) json_encode($entry->properties);
        $this->assertStringNotContainsString('GOCSPX-leak', $log);
        $this->assertStringNotContainsString('client-id-leak', $log);
        $this->assertSame(['enabled' => true, 'client_id_changed' => true, 'credentials_replaced' => true], $entry->properties);
    }

    // -- Adresse de retour ----------------------------------------------------------------------------------------------

    public function test_the_redirect_uri_follows_the_public_url_of_the_instance(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/google-calendar/settings')->assertSee(url('/google-calendar/callback'), false);

        app(Settings::class)->set('organization.public_url', 'https://Kintai.Example.com/app/');

        $this->actingAs($owner)->get('/google-calendar/settings')->assertSee('https://kintai.example.com/app/google-calendar/callback', false);
    }

    // -- Test des identifiants ------------------------------------------------------------------------------------------

    public function test_the_credentials_test_reports_each_outcome(): void
    {
        $owner = $this->owner();
        $component = Livewire::actingAs($owner)->test('google-calendar.settings');

        $component->call('test')->assertSet('check', 'incomplete')->assertSee('Enter and save the client ID and secret before testing.');
        $this->assertCount(0, Http::recorded());

        $this->configureGoogle();

        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $component->call('test')->assertSet('check', 'ok')->assertSee('Google accepts the client ID and secret.');

        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client'], 401)]);
        $component->call('test')->assertSet('check', 'invalid_client');

        $this->fakeNetworkDown();
        $component->call('test')->assertSet('check', 'unreachable');
    }

    public function test_the_credentials_test_sends_the_saved_redirect_uri_and_never_links_anything(): void
    {
        $this->configureGoogle();
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        Livewire::actingAs($this->owner())->test('google-calendar.settings')->call('test');

        $this->assertSame(url('/google-calendar/callback'), $this->sent('POST', 'oauth2.googleapis.com/token')->sole()['redirect_uri']);
        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_the_page_shows_the_number_of_linked_users_and_availability(): void
    {
        $this->connection($this->employee());
        $this->connection($this->employee());
        $this->configureGoogle();

        Livewire::actingAs($this->owner())->test('google-calendar.settings')->assertSee('2 linked user(s)')->assertSee('Active');
    }
}
