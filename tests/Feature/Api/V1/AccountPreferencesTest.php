<?php

namespace Tests\Feature\Api\V1;

use App\Models\DashboardLayout;
use App\Models\DevicePushToken;
use App\Models\NotificationPreference;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNavPref;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * Endpoints `/users/{user}/...` : soi-même, un gérant sur un employé de son magasin, jamais un autre utilisateur.
 */
class AccountPreferencesTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    private Store $store;

    private User $employee;

    private User $colleague;

    private User $manager;

    private User $foreignManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->employee = $this->employeeOf($this->store);
        $this->colleague = $this->employeeOf($this->store);
        $this->manager = $this->managerOf($this->store);
        $this->foreignManager = $this->managerOf(Store::factory()->create());
    }

    /**
     * Les endpoints par utilisateur : méthode, chemin, corps, permission du gérant (lecture : view, écriture : update).
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function endpoints(): array
    {
        return [
            'notification preferences (read)' => ['GET', 'notification-preferences', []],
            'notification preferences (write)' => ['PUT', 'notification-preferences', ['quiet_hours' => ['enabled' => false]]],
            'push token (register)' => ['POST', 'push-tokens', ['token' => 'fcm-token-1', 'platform' => 'ios']],
            'push token (remove)' => ['DELETE', 'push-tokens', ['token' => 'fcm-token-1']],
            'dashboard (read)' => ['GET', 'dashboard-prefs', []],
            'dashboard (write)' => ['PUT', 'dashboard-prefs', ['reset' => true]],
            'navigation (read)' => ['GET', 'nav-prefs', []],
            'navigation (write)' => ['PUT', 'nav-prefs', ['hidden' => []]],
            'calendar links (list)' => ['GET', 'ical-tokens', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('endpoints')]
    public function test_a_colleague_and_a_manager_of_another_store_are_forbidden(string $method, string $path, array $body): void
    {
        foreach ([$this->colleague, $this->foreignManager] as $actor) {
            $this->actingWithToken($actor)
                ->json($method, '/api/v1/users/'.$this->employee->id.'/'.$path, $body)
                ->assertStatus(403)
                ->assertJsonPath('code', 'forbidden');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('endpoints')]
    public function test_the_user_himself_and_his_manager_are_allowed(string $method, string $path, array $body): void
    {
        foreach ([$this->employee, $this->manager] as $actor) {
            $status = $this->actingWithToken($actor)
                ->json($method, '/api/v1/users/'.$this->employee->id.'/'.$path, $body)
                ->getStatusCode();

            $this->assertContains($status, [200, 201, 204], $actor->id.' '.$method.' '.$path.' => '.$status);
        }
    }

    public function test_a_manager_cannot_act_on_an_owner_and_unknown_users_stay_hidden(): void
    {
        $owner = $this->owner();
        $owner->stores()->attach($this->store);

        $this->actingWithToken($this->manager)->getJson('/api/v1/users/'.$owner->id.'/nav-prefs')->assertStatus(403);
        $this->getJson('/api/v1/users/9999/nav-prefs')->assertStatus(404);

        // Un employé sans droit ne peut pas deviner quels comptes existent.
        $this->actingWithToken($this->employee)->getJson('/api/v1/users/9999/nav-prefs')->assertStatus(403);
    }

    public function test_the_user_id_must_be_a_number(): void
    {
        $this->actingWithToken($this->employee)->getJson('/api/v1/users/me/nav-prefs')->assertStatus(404);
    }

    // --- Préférences de notification ---

    public function test_notification_preferences_list_types_channels_and_quiet_hours(): void
    {
        $response = $this->actingWithToken($this->employee)
            ->getJson('/api/v1/users/'.$this->employee->id.'/notification-preferences')->assertOk();

        $response->assertJsonPath('data.user_id', $this->employee->id)
            ->assertJsonPath('data.quiet_hours', ['enabled' => false, 'start' => '22:00', 'end' => '07:00']);

        $types = collect($response->json('data.preferences'))->keyBy('type');
        $this->assertTrue($types->has('shift_assigned'));
        $this->assertFalse($types->has('notification_test'), 'internal types are hidden');
        $this->assertSame(['type', 'category', 'label', 'push', 'mail'], array_keys($types['shift_assigned']));
        $this->assertTrue($types['shift_assigned']['push']);
        $this->assertTrue($types['shift_assigned']['mail']);
        $this->assertFalse($types['shift_updated']['mail']);
    }

    public function test_notification_preferences_change_only_what_is_sent(): void
    {
        $response = $this->actingWithToken($this->employee)->putJson('/api/v1/users/'.$this->employee->id.'/notification-preferences', [
            'preferences' => ['shift_assigned' => ['mail' => false], 'shift_updated' => ['mail' => true]],
            'quiet_hours' => ['enabled' => true, 'start' => '23:00', 'end' => '06:30'],
        ])->assertOk();

        $types = collect($response->json('data.preferences'))->keyBy('type');
        $this->assertFalse($types['shift_assigned']['mail']);
        $this->assertTrue($types['shift_assigned']['push'], 'channel not sent is unchanged');
        $this->assertTrue($types['shift_updated']['mail']);
        $this->assertTrue($types['shift_deleted']['push'], 'type not sent is unchanged');
        $response->assertJsonPath('data.quiet_hours', ['enabled' => true, 'start' => '23:00', 'end' => '06:30']);

        $this->employee->refresh();
        $this->assertSame('23:00', $this->employee->quiet_hours_start);

        // Une deuxième requête sans heures silencieuses les garde.
        $this->putJson('/api/v1/users/'.$this->employee->id.'/notification-preferences', ['preferences' => ['shift_assigned' => ['push' => false]]])
            ->assertOk()->assertJsonPath('data.quiet_hours.enabled', true);
        $this->assertSame('23:00', $this->employee->refresh()->quiet_hours_start);
    }

    public function test_notification_preferences_reject_unknown_types_and_bad_quiet_hours(): void
    {
        $url = '/api/v1/users/'.$this->employee->id.'/notification-preferences';
        $this->actingWithToken($this->employee);

        $this->putJson($url, ['preferences' => ['not_a_type' => ['push' => true]]])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors']);
        $this->putJson($url, ['preferences' => ['shift_assigned' => ['push' => 'maybe']]])->assertStatus(422);
        $this->putJson($url, ['quiet_hours' => ['enabled' => true, 'start' => '25:00', 'end' => '07:00']])->assertStatus(422);
        $this->putJson($url, ['quiet_hours' => ['enabled' => true, 'start' => '07:00', 'end' => '07:00']])->assertStatus(422);

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_a_manager_edits_the_preferences_of_an_employee_of_his_store(): void
    {
        $this->actingWithToken($this->manager)
            ->putJson('/api/v1/users/'.$this->employee->id.'/notification-preferences', ['preferences' => ['shift_assigned' => ['push' => false]]])
            ->assertOk();

        $this->assertTrue(NotificationPreference::query()->where('user_id', $this->employee->id)->where('type', 'shift_assigned')->where('channel', 'push')->where('enabled', false)->exists());
        $this->assertFalse(NotificationPreference::query()->where('user_id', $this->manager->id)->exists());
    }

    // --- Jetons push (FCM) ---

    public function test_a_device_token_is_registered_and_refreshed(): void
    {
        $url = '/api/v1/users/'.$this->employee->id.'/push-tokens';

        $created = $this->actingWithToken($this->employee)->postJson($url, ['token' => 'fcm-token-1', 'platform' => 'android'])
            ->assertCreated()->assertJsonPath('data.platform', 'android');
        $this->assertStringNotContainsString('fcm-token-1', (string) $created->getContent());

        $this->postJson($url, ['token' => 'fcm-token-1', 'platform' => 'android'])->assertOk();
        $this->assertSame(1, DevicePushToken::query()->count());
        $this->assertSame($this->employee->id, DevicePushToken::query()->firstOrFail()->user_id);
    }

    public function test_a_device_token_is_validated(): void
    {
        $url = '/api/v1/users/'.$this->employee->id.'/push-tokens';
        $this->actingWithToken($this->employee);

        $this->postJson($url, ['platform' => 'ios'])->assertStatus(422)->assertJsonValidationErrors(['token'], 'errors');
        $this->postJson($url, ['token' => 'abc', 'platform' => 'windows'])->assertStatus(422)->assertJsonValidationErrors(['platform'], 'errors');
        $this->postJson($url, ['token' => str_repeat('x', 300), 'platform' => 'ios'])->assertStatus(422);
        $this->postJson($url, ['token' => ['a'], 'platform' => 'ios'])->assertStatus(422);

        $this->assertSame(0, DevicePushToken::query()->count());
    }

    public function test_a_device_token_is_removed_for_that_user_only(): void
    {
        DevicePushToken::query()->create(['user_id' => $this->employee->id, 'token' => 'mine', 'platform' => 'ios']);
        DevicePushToken::query()->create(['user_id' => $this->colleague->id, 'token' => 'theirs', 'platform' => 'ios']);

        $this->actingWithToken($this->employee)->deleteJson('/api/v1/users/'.$this->employee->id.'/push-tokens', ['token' => 'theirs'])->assertNoContent();
        $this->assertTrue(DevicePushToken::query()->where('token', 'theirs')->exists(), 'the token of another user is not touched');

        $this->deleteJson('/api/v1/users/'.$this->employee->id.'/push-tokens', ['token' => 'mine'])->assertNoContent();
        $this->assertFalse(DevicePushToken::query()->where('token', 'mine')->exists());

        $this->deleteJson('/api/v1/users/'.$this->employee->id.'/push-tokens', [])->assertStatus(422);
    }

    // --- Tableau de bord ---

    public function test_dashboard_prefs_show_the_resolved_layout_and_the_available_widgets(): void
    {
        $response = $this->actingWithToken($this->employee)
            ->getJson('/api/v1/users/'.$this->employee->id.'/dashboard-prefs')->assertOk();

        $response->assertJsonPath('data.dashboard', 'employee')
            ->assertJsonPath('data.source', 'default')
            ->assertJsonPath('data.layout', null)
            ->assertJsonStructure(['data' => ['user_id', 'widgets' => [['widget', 'label', 'size']], 'available' => [['widget', 'label', 'size', 'resizable', 'default']]]]);

        $available = array_column($response->json('data.available'), 'widget');
        $this->assertContains('my-month', $available);
        $this->assertNotContains('counters', $available, 'manager widgets are not offered on the employee dashboard');

        $this->getJson('/api/v1/users/'.$this->employee->id.'/dashboard-prefs?dashboard=bogus')->assertStatus(422);
    }

    public function test_a_manager_gets_the_manager_dashboard_by_default(): void
    {
        $this->actingWithToken($this->manager)->getJson('/api/v1/users/'.$this->manager->id.'/dashboard-prefs')
            ->assertOk()->assertJsonPath('data.dashboard', 'manager');
    }

    public function test_dashboard_prefs_save_a_personal_layout_then_reset(): void
    {
        $url = '/api/v1/users/'.$this->employee->id.'/dashboard-prefs';
        $this->actingWithToken($this->employee);

        $saved = $this->putJson($url, ['widgets' => [['widget' => 'my-month', 'size' => 'full'], ['widget' => 'my-upcoming-shifts'], ['widget' => 'counters'], ['widget' => 'unknown']]])
            ->assertOk();

        $saved->assertJsonPath('data.source', 'personal');
        $this->assertSame(['my-month', 'my-upcoming-shifts'], array_column($saved->json('data.widgets'), 'widget'));
        $this->assertSame('full', $saved->json('data.widgets.0.size'));
        $this->assertSame(1, DashboardLayout::query()->where('user_id', $this->employee->id)->where('is_active', true)->count());

        // Une deuxième sauvegarde met à jour la disposition active au lieu d'en créer une autre.
        $this->putJson($url, ['widgets' => [['widget' => 'my-month']]])->assertOk();
        $this->assertSame(1, DashboardLayout::query()->where('user_id', $this->employee->id)->count());

        $this->getJson($url)->assertJsonPath('data.source', 'personal')->assertJsonCount(1, 'data.widgets');

        $this->putJson($url, ['reset' => true])->assertOk()->assertJsonPath('data.source', 'default');
        $this->assertFalse(DashboardLayout::query()->where('user_id', $this->employee->id)->where('is_active', true)->exists());
    }

    public function test_dashboard_prefs_refuse_an_empty_layout(): void
    {
        $url = '/api/v1/users/'.$this->employee->id.'/dashboard-prefs';
        $this->actingWithToken($this->employee);

        $this->putJson($url, ['widgets' => [['widget' => 'counters']]])->assertStatus(422)->assertJsonValidationErrors(['layout'], 'errors');
        $this->putJson($url, [])->assertStatus(422);
        $this->putJson($url, ['widgets' => 'my-month'])->assertStatus(422);
    }

    // --- Navigation ---

    public function test_nav_prefs_show_and_save(): void
    {
        $url = '/api/v1/users/'.$this->employee->id.'/nav-prefs';

        $this->actingWithToken($this->employee)->getJson($url)->assertOk()
            ->assertJsonPath('data.hidden', [])
            ->assertJsonPath('data.bottom', null)
            ->assertJsonStructure(['data' => ['effective_bottom', 'items' => [['key', 'label', 'hideable']]]]);

        $saved = $this->putJson($url, ['hidden' => ['exports', 'not-an-item'], 'bottom' => ['dashboard', 'notifications']])->assertOk();

        $saved->assertJsonPath('data.hidden', ['exports'])
            ->assertJsonPath('data.bottom', ['dashboard', 'notifications'])
            ->assertJsonPath('data.effective_bottom', ['dashboard', 'notifications']);

        // Une clé absente garde sa valeur.
        $this->putJson($url, ['hidden' => []])->assertOk()->assertJsonPath('data.bottom', ['dashboard', 'notifications'])->assertJsonPath('data.hidden', []);

        // Barre vide = barre par défaut.
        $this->putJson($url, ['bottom' => []])->assertOk()->assertJsonPath('data.bottom', null);
        $this->assertNull(UserNavPref::query()->where('user_id', $this->employee->id)->firstOrFail()->bottom_nav_items);
    }

    public function test_nav_prefs_can_be_saved_for_the_first_time_with_one_key_only(): void
    {
        $this->actingWithToken($this->employee)
            ->putJson('/api/v1/users/'.$this->employee->id.'/nav-prefs', ['hidden' => ['exports']])
            ->assertOk()->assertJsonPath('data.hidden', ['exports'])->assertJsonPath('data.bottom', null);
    }

    public function test_nav_prefs_refuse_a_bar_of_the_wrong_size(): void
    {
        $this->actingWithToken($this->employee)
            ->putJson('/api/v1/users/'.$this->employee->id.'/nav-prefs', ['bottom' => ['dashboard']])
            ->assertStatus(422)->assertJsonValidationErrors(['bottom'], 'errors');

        $this->putJson('/api/v1/users/'.$this->employee->id.'/nav-prefs', ['bottom' => ['dashboard', 'notifications', 'exports', 'my-shifts', 'settings']])->assertStatus(422);
        $this->putJson('/api/v1/users/'.$this->employee->id.'/nav-prefs', ['hidden' => 'exports'])->assertStatus(422);
    }
}
