<?php

namespace Kintai\Bundles\GoogleCalendar\Tests;

use App\Models\ActivityEntry;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Bundles\GoogleCalendar\Models\CalendarEventLink;
use Kintai\Bundles\GoogleCalendar\Models\CalendarSyncQueueItem;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/GoogleCalendarFixtures.php';

/**
 * Liaison d'un agenda par son propriétaire : page personnelle, départ vers Google (PKCE), retour (état anti-CSRF à usage
 * unique, refus, échec), nom de l'agenda, resynchronisation, déconnexion. Chacun ne touche que sa propre liaison.
 */
class ConnectionFlowTest extends TestCase
{
    use GoogleCalendarFixtures;
    use RefreshDatabase;

    private const SESSION_KEY = 'google-calendar-oauth';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootGoogleCalendar();
        $this->configureGoogle();
        $this->fakeGoogle();
    }

    /**
     * Une liaison en cours, comme la session de la personne l'a mémorisée au départ.
     *
     * @return array<string, mixed>
     */
    private function pending(array $overrides = []): array
    {
        return [self::SESSION_KEY => $overrides + ['state' => 'state-abc', 'verifier' => 'verifier-xyz', 'at' => time(), 'name_mode' => 'instance', 'store_id' => null]];
    }

    private function idToken(string $email): string
    {
        return 'h.'.rtrim(strtr(base64_encode((string) json_encode(['email' => $email])), '+/', '-_'), '=').'.s';
    }

    // -- Page personnelle -----------------------------------------------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/google-calendar')->assertRedirect('/login');
        $this->get('/google-calendar/callback?state=x&code=y')->assertRedirect('/login');
    }

    public function test_the_page_is_hidden_until_the_owner_enables_the_feature_unless_already_linked(): void
    {
        $ana = $this->employee();
        $bob = $this->employee();
        $this->connection($bob);
        $this->configureGoogle(false);

        $this->actingAs($ana)->get('/google-calendar')->assertNotFound();
        $this->actingAs($bob)->get('/google-calendar')->assertOk();

        $this->configureGoogle();
        $this->actingAs($ana)->get('/google-calendar')->assertOk()->assertSee('Connect Google Calendar');
    }

    public function test_the_page_shows_the_link_without_ever_showing_the_token(): void
    {
        $ana = $this->employee();
        $this->connection($ana, ['refresh_token' => 'visible-nowhere-token', 'account_email' => 'ana@gmail.test', 'calendar_name' => 'Acme Corp', 'last_error' => 'Calendar API: Bad Request']);

        $this->actingAs($ana)->get('/google-calendar')
            ->assertOk()
            ->assertSee('ana@gmail.test')
            ->assertSee('Acme Corp')
            ->assertSee('Calendar API: Bad Request')
            ->assertDontSee('visible-nowhere-token');
    }

    public function test_a_revoked_link_offers_to_reconnect(): void
    {
        $ana = $this->employee();
        CalendarConnection::factory()->revoked()->create(['user_id' => $ana->id]);

        Livewire::actingAs($ana)->test('google-calendar.profile')
            ->assertSee('Kintai no longer has access to your Google Calendar')
            ->assertSee('Reconnect Google Calendar')
            ->assertDontSee('Resync now');
    }

    public function test_the_page_lists_the_stores_of_the_user_only(): void
    {
        Store::factory()->create(['name' => 'Elsewhere']);
        $ana = $this->employee();

        Livewire::actingAs($ana)->test('google-calendar.profile')->set('nameMode', 'store')->assertSee('Alpha')->assertDontSee('Elsewhere');
    }

    // -- Départ vers Google ---------------------------------------------------------------------------------------------

    public function test_connecting_sends_the_user_to_google_with_a_fresh_state_and_pkce(): void
    {
        $ana = $this->employee();

        $component = Livewire::actingAs($ana)->test('google-calendar.profile')->call('connect')->assertHasNoErrors();

        $url = (string) $component->effects['redirect'];
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $pending = session(self::SESSION_KEY);

        $this->assertSame('client-id-123', $query['client_id']);
        $this->assertSame(url('/google-calendar/callback'), $query['redirect_uri']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame(48, strlen($pending['state']));
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertSame($ana->email, $query['login_hint']);
        $this->assertStringNotContainsString('client-secret-xyz', $url);
        $this->assertStringNotContainsString($pending['verifier'], $url);
    }

    public function test_connecting_is_refused_when_the_feature_is_off_or_the_store_is_not_the_users(): void
    {
        $ana = $this->employee();
        $elsewhere = Store::factory()->create();

        Livewire::actingAs($ana)->test('google-calendar.profile')
            ->set('nameMode', 'store')->set('storeId', (string) $elsewhere->id)->call('connect')
            ->assertHasErrors('storeId')->assertNoRedirect();
        $this->assertNull(session(self::SESSION_KEY));

        $this->configureGoogle(false);
        $bob = $this->employee();
        $this->connection($bob);
        Livewire::actingAs($bob)->test('google-calendar.profile')->call('connect')->assertNoRedirect();
    }

    // -- Retour de Google -----------------------------------------------------------------------------------------------

    public function test_the_callback_links_the_account_and_starts_the_first_sync(): void
    {
        $ana = $this->employee();
        $shift = $this->shiftFor($ana);
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['refresh_token' => 'refresh-from-google', 'access_token' => 'access-1', 'id_token' => $this->idToken('ana@gmail.test')])]);

        $this->actingAs($ana)->withSession($this->pending())
            ->get('/google-calendar/callback?state=state-abc&code=auth-code&scope=whatever')
            ->assertRedirect(route('google-calendar.profile'))
            ->assertSessionHas('google_calendar_flash.variant', 'success');

        $exchange = $this->sent('POST', 'oauth2.googleapis.com/token')->first();
        $this->assertSame('authorization_code', $exchange['grant_type']);
        $this->assertSame('auth-code', $exchange['code']);
        $this->assertSame('verifier-xyz', $exchange['code_verifier']);
        $this->assertSame(url('/google-calendar/callback'), $exchange['redirect_uri']);

        $connection = CalendarConnection::query()->where('user_id', $ana->id)->sole();
        $this->assertSame('refresh-from-google', $connection->refresh_token);
        $this->assertSame('ana@gmail.test', $connection->account_email);
        $this->assertTrue($connection->isActive());
        $this->assertSame('cal-new', $connection->calendar_id, 'The calendar appears in Google at once.');
        $this->assertSame(['shift:'.$shift->id], CalendarSyncQueueItem::query()->where('connection_id', $connection->id)->get()->map(fn ($i) => $i->source_type.':'.$i->source_key)->all());
        $this->assertNull(session(self::SESSION_KEY));

        $entry = ActivityEntry::query()->where('action', 'google_calendar.connected')->sole();
        $this->assertSame($ana->id, $entry->actor_id);
        $this->assertStringNotContainsString('refresh-from-google', (string) json_encode($entry->properties));
        $this->assertStringNotContainsString('ana@gmail.test', (string) json_encode($entry->properties));
    }

    public function test_the_callback_keeps_the_store_name_choice(): void
    {
        $ana = $this->employee();

        $this->actingAs($ana)->withSession($this->pending(['name_mode' => 'store', 'store_id' => $this->store->id]))
            ->get('/google-calendar/callback?state=state-abc&code=auth-code')->assertRedirect();

        $connection = CalendarConnection::query()->where('user_id', $ana->id)->sole();
        $this->assertSame('store', $connection->name_mode);
        $this->assertSame($this->store->id, $connection->store_id);
        $this->assertSame('Alpha', $connection->calendar_name);
    }

    public function test_linking_again_replaces_the_previous_link_with_its_events_and_queue(): void
    {
        $ana = $this->employee();
        $old = $this->connection($ana);
        CalendarEventLink::create(['connection_id' => $old->id, 'source_type' => 'shift', 'source_key' => '1', 'external_event_id' => 'evt-old', 'content_hash' => str_repeat('a', 64)]);

        $this->actingAs($ana)->withSession($this->pending())->get('/google-calendar/callback?state=state-abc&code=auth-code')->assertRedirect();

        $this->assertSame(1, CalendarConnection::where('user_id', $ana->id)->count());
        $this->assertSame('refresh-token-1', CalendarConnection::where('user_id', $ana->id)->sole()->refresh_token);
        $this->assertSame(0, CalendarEventLink::count());
    }

    public function test_the_callback_refuses_a_state_that_is_wrong_missing_expired_or_replayed(): void
    {
        $ana = $this->employee();
        $cases = [
            'wrong' => [$this->pending(), '/google-calendar/callback?state=other&code=c'],
            'missing' => [$this->pending(), '/google-calendar/callback?code=c'],
            'no session' => [[], '/google-calendar/callback?state=state-abc&code=c'],
            'expired' => [$this->pending(['at' => time() - 601]), '/google-calendar/callback?state=state-abc&code=c'],
        ];

        foreach ($cases as $label => [$session, $uri]) {
            $this->flushSession();
            $this->actingAs($ana)->withSession($session)->get($uri)
                ->assertRedirect(route('google-calendar.profile'))
                ->assertSessionHas('google_calendar_flash.variant', 'danger');
            $this->assertSame(0, CalendarConnection::count(), $label);
            $this->assertCount(0, $this->sent('POST', 'oauth2.googleapis.com/token'), $label);
        }

        // Un état valide ne sert qu'une fois : le rejeu est refusé même avec le bon `state`.
        $this->flushSession();
        $this->actingAs($ana)->withSession($this->pending())->get('/google-calendar/callback?state=state-abc&code=c')->assertSessionHas('google_calendar_flash.variant', 'success');
        $this->get('/google-calendar/callback?state=state-abc&code=c')->assertSessionHas('google_calendar_flash.variant', 'danger');
        $this->assertCount(1, $this->sent('POST', 'oauth2.googleapis.com/token')->filter(fn ($r) => $r['grant_type'] === 'authorization_code'));
    }

    public function test_an_overlong_or_malformed_callback_is_sent_back_with_a_message(): void
    {
        $this->actingAs($this->employee())->withSession($this->pending())
            ->get('/google-calendar/callback?state=state-abc&code='.str_repeat('a', 3000))
            ->assertRedirect(route('google-calendar.profile'))
            ->assertSessionHas('google_calendar_flash.variant', 'danger');

        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_a_denied_authorisation_links_nothing(): void
    {
        $ana = $this->employee();

        $this->actingAs($ana)->withSession($this->pending())->get('/google-calendar/callback?state=state-abc&error=access_denied')
            ->assertRedirect(route('google-calendar.profile'))
            ->assertSessionHas('google_calendar_flash.variant', 'warning');

        $this->assertSame(0, CalendarConnection::count());
        $this->assertCount(0, Http::recorded());
    }

    public function test_a_refusal_from_google_or_a_missing_refresh_token_links_nothing(): void
    {
        $ana = $this->employee();

        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad code'], 400)]);
        $this->actingAs($ana)->withSession($this->pending())->get('/google-calendar/callback?state=state-abc&code=c')
            ->assertSessionHas('google_calendar_flash.variant', 'danger');

        $this->flushSession();
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['access_token' => 'only-access'])]);
        $this->actingAs($ana)->withSession($this->pending())->get('/google-calendar/callback?state=state-abc&code=c')
            ->assertSessionHas('google_calendar_flash.variant', 'danger');

        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_the_callback_does_nothing_when_the_feature_was_turned_off_meanwhile(): void
    {
        $this->configureGoogle(false);

        $this->actingAs($this->employee())->withSession($this->pending())->get('/google-calendar/callback?state=state-abc&code=c')
            ->assertSessionHas('google_calendar_flash.variant', 'warning');

        $this->assertSame(0, CalendarConnection::count());
        $this->assertCount(0, $this->sent('POST', 'oauth2.googleapis.com/token'));
    }

    // -- Nom, resynchronisation, déconnexion ---------------------------------------------------------------------------

    public function test_the_calendar_can_be_renamed_after_the_name_of_one_of_my_stores(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana, ['calendar_name' => 'Kintai']);

        Livewire::actingAs($ana)->test('google-calendar.profile')
            ->set('nameMode', 'store')->set('storeId', (string) $this->store->id)->call('saveOptions')->assertHasNoErrors();

        $link->refresh();
        $this->assertSame('store', $link->name_mode);
        $this->assertSame($this->store->id, $link->store_id);
        $this->assertSame('Alpha', $link->calendar_name);
        $this->assertSame('Alpha', $this->sentEnding('PATCH', '/calendars/cal-1')->sole()['summary']);
    }

    public function test_the_name_of_a_store_that_is_not_mine_is_refused(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $elsewhere = Store::factory()->create(['name' => 'Secret store']);

        Livewire::actingAs($ana)->test('google-calendar.profile')
            ->set('nameMode', 'store')->set('storeId', (string) $elsewhere->id)->call('saveOptions')->assertHasErrors('storeId');

        $this->assertSame('instance', $link->fresh()?->name_mode);
        $this->assertCount(0, Http::recorded());
    }

    public function test_resyncing_queues_the_window_again(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);

        Livewire::actingAs($ana)->test('google-calendar.profile')->call('resync');

        $this->assertSame(['shift:'.$shift->id], CalendarSyncQueueItem::query()->where('connection_id', $link->id)->get()->map(fn ($i) => $i->source_type.':'.$i->source_key)->all());
    }

    public function test_options_and_resync_need_an_active_link(): void
    {
        $ana = $this->employee();
        Livewire::actingAs($ana)->test('google-calendar.profile')->call('resync')->assertNotFound();
        Livewire::actingAs($ana)->test('google-calendar.profile')->call('saveOptions')->assertNotFound();
        Livewire::actingAs($ana)->test('google-calendar.profile')->call('disconnect')->assertNotFound();
    }

    public function test_disconnecting_revokes_the_token_and_deletes_the_link_the_events_and_the_queue(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana, ['refresh_token' => 'the-refresh-token']);
        CalendarEventLink::create(['connection_id' => $link->id, 'source_type' => 'shift', 'source_key' => '5', 'external_event_id' => 'evt-5', 'content_hash' => str_repeat('b', 64)]);
        $this->shiftFor($ana);
        CalendarSyncQueueItem::create(['connection_id' => $link->id, 'source_type' => 'shift', 'source_key' => '5']);

        Livewire::actingAs($ana)->test('google-calendar.profile')->call('disconnect');

        $this->assertSame('the-refresh-token', $this->sent('POST', 'oauth2.googleapis.com/revoke')->sole()['token']);
        $this->assertCount(0, $this->sent('DELETE', '/events/'), 'Events stay in the calendar unless asked.');
        $this->assertSame([0, 0, 0], [CalendarConnection::count(), CalendarEventLink::count(), CalendarSyncQueueItem::count()]);

        $entry = ActivityEntry::query()->where('action', 'google_calendar.disconnected')->sole();
        $this->assertSame($ana->id, $entry->actor_id);
        $this->assertStringNotContainsString('the-refresh-token', (string) json_encode($entry->properties));
    }

    public function test_disconnecting_can_delete_the_events_from_the_calendar(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        foreach (['5', '6'] as $key) {
            CalendarEventLink::create(['connection_id' => $link->id, 'source_type' => 'shift', 'source_key' => $key, 'external_event_id' => 'evt-'.$key, 'content_hash' => str_repeat('b', 64)]);
        }

        Livewire::actingAs($ana)->test('google-calendar.profile')->set('deleteEvents', true)->call('disconnect');

        $this->assertCount(2, $this->sent('DELETE', '/calendars/cal-1/events/'));
        $this->assertCount(1, $this->sent('POST', 'oauth2.googleapis.com/revoke'));
        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_a_network_failure_never_prevents_the_local_disconnection(): void
    {
        $ana = $this->employee();
        $this->connection($ana);
        $this->fakeNetworkDown();

        Livewire::actingAs($ana)->test('google-calendar.profile')->set('deleteEvents', true)->call('disconnect');

        $this->assertSame(0, CalendarConnection::count());
    }

    public function test_nobody_can_touch_the_link_of_someone_else(): void
    {
        $ana = $this->employee();
        $bob = $this->employee();
        $bobLink = $this->connection($bob);

        // Ana n'a pas de liaison : toutes les actions échouent, celle de Bob reste intacte.
        Livewire::actingAs($ana)->test('google-calendar.profile')->call('disconnect')->assertNotFound();
        Livewire::actingAs($ana)->test('google-calendar.profile')->call('resync')->assertNotFound();

        $this->assertNotNull(CalendarConnection::find($bobLink->id));
        $this->assertCount(0, Http::recorded());
    }
}
