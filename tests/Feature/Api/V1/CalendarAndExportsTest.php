<?php

namespace Tests\Feature\Api\V1;

use App\Models\ActivityEntry;
use App\Models\IcalToken;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

class CalendarAndExportsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesApi;

    private Store $store;

    private User $employee;

    private User $colleague;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha', 'code' => 'ALPHA']);
        $this->employee = $this->employeeOf($this->store);
        $this->colleague = $this->employeeOf($this->store);
        $this->manager = $this->managerOf($this->store);
    }

    private function base(?User $user = null): string
    {
        return '/api/v1/users/'.($user ?? $this->employee)->id.'/ical-tokens';
    }

    // --- Liens iCal ---

    public function test_a_link_is_created_once_with_the_clear_token_and_the_feed_url(): void
    {
        $response = $this->actingWithToken($this->employee)->postJson($this->base(), ['store_id' => $this->store->id])
            ->assertCreated()
            ->assertJsonPath('data.store_id', $this->store->id)
            ->assertJsonPath('data.store_name', 'Alpha')
            ->assertJsonStructure(['data' => ['token', 'url', 'created_at', 'updated_at']]);

        $token = $response->json('data.token');
        $this->assertSame(64, strlen($token));
        $this->assertStringContainsString($token, $response->json('data.url'));
        $this->assertSame(hash('sha256', $token), DB::table('ical_tokens')->value('token_hash'));

        // L'URL renvoyée fonctionne.
        $this->get($response->json('data.url'))->assertOk();
    }

    public function test_a_second_creation_is_refused_and_keeps_the_working_link(): void
    {
        $first = $this->actingWithToken($this->employee)->postJson($this->base(), ['store_id' => $this->store->id])->json('data.token');

        $this->postJson($this->base(), ['store_id' => $this->store->id])
            ->assertStatus(422)->assertJsonValidationErrors(['store_id'], 'errors');

        $this->get('/ical/'.$first.'/'.$this->store->id.'/shifts.ics')->assertOk();
    }

    public function test_the_value_of_a_link_is_never_read_back(): void
    {
        $token = $this->actingWithToken($this->employee)->postJson($this->base(), ['store_id' => $this->store->id])->json('data.token');

        $list = $this->getJson($this->base())->assertOk()->assertJsonCount(1, 'data');
        $show = $this->getJson($this->base().'/'.$this->store->id)->assertOk();

        foreach ([$list, $show] as $response) {
            $this->assertStringNotContainsString($token, (string) $response->getContent());
            $this->assertStringNotContainsString(hash('sha256', $token), (string) $response->getContent());
            $response->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.url');
        }
        $show->assertJsonPath('data.store_id', $this->store->id)->assertJsonPath('data.store_name', 'Alpha');
    }

    public function test_regenerating_replaces_the_link(): void
    {
        $old = $this->actingWithToken($this->employee)->postJson($this->base(), ['store_id' => $this->store->id])->json('data.token');

        $new = $this->postJson($this->base().'/'.$this->store->id.'/regenerate')->assertOk()->json('data.token');

        $this->assertNotSame($old, $new);
        $this->get('/ical/'.$old.'/'.$this->store->id.'/shifts.ics')->assertStatus(404);
        $this->get('/ical/'.$new.'/'.$this->store->id.'/shifts.ics')->assertOk();
        $this->assertSame(1, IcalToken::query()->count());
    }

    public function test_deleting_a_link_makes_the_feed_unavailable(): void
    {
        $token = $this->actingWithToken($this->employee)->postJson($this->base(), ['store_id' => $this->store->id])->json('data.token');

        $this->deleteJson($this->base().'/'.$this->store->id)->assertNoContent();

        $this->get('/ical/'.$token.'/'.$this->store->id.'/shifts.ics')->assertStatus(404);
        $this->getJson($this->base().'/'.$this->store->id)->assertStatus(404);
        $this->deleteJson($this->base().'/'.$this->store->id)->assertStatus(404);
    }

    public function test_a_link_needs_membership_of_the_store(): void
    {
        $other = Store::factory()->create();

        $this->actingWithToken($this->employee)->postJson($this->base(), ['store_id' => $other->id])->assertStatus(422);
        $this->postJson($this->base(), ['store_id' => 99999])->assertStatus(404);
        $this->postJson($this->base(), [])->assertStatus(422);
        $this->assertSame(0, IcalToken::query()->count());
    }

    public function test_links_of_another_user_follow_the_rule_self_or_manager(): void
    {
        $this->actingWithToken($this->colleague);
        $this->getJson($this->base())->assertStatus(403);
        $this->postJson($this->base(), ['store_id' => $this->store->id])->assertStatus(403);
        $this->postJson($this->base().'/'.$this->store->id.'/regenerate')->assertStatus(403);
        $this->deleteJson($this->base().'/'.$this->store->id)->assertStatus(403);
        $this->assertSame(0, IcalToken::query()->count());

        // Le gérant du magasin le peut, et c'est journalisé à son nom.
        $this->actingWithToken($this->manager);
        $this->postJson($this->base(), ['store_id' => $this->store->id])->assertCreated();
        $this->getJson($this->base())->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->base().'/'.$this->store->id.'/regenerate')->assertOk();
        $entry = ActivityEntry::query()->where('action', 'ical.token_regenerated')->latest('id')->firstOrFail();
        $this->assertSame($this->manager->id, $entry->actor_id);
        $this->deleteJson($this->base().'/'.$this->store->id)->assertNoContent();
    }

    public function test_a_manager_of_another_store_cannot_touch_the_links(): void
    {
        $foreign = $this->managerOf(Store::factory()->create());

        $this->actingWithToken($foreign)->postJson($this->base(), ['store_id' => $this->store->id])->assertStatus(403);
        $this->getJson($this->base())->assertStatus(403);
    }

    // --- Export du planning ---

    private function shift(User $user, string $date): Shift
    {
        return Shift::factory()->create(['store_id' => $this->store->id, 'user_id' => $user->id, 'shift_date' => $date, 'notes' => 'Note '.$user->id]);
    }

    public function test_the_schedule_export_contains_only_my_shifts_as_json(): void
    {
        $this->shift($this->employee, '2026-11-10');
        $this->shift($this->colleague, '2026-11-10');

        $response = $this->actingWithToken($this->employee)
            ->get('/api/v1/me/schedule-export?store='.$this->store->id.'&from=2026-11-01&to=2026-11-30')
            ->assertOk();

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $data = json_decode($response->streamedContent(), true);
        $this->assertSame($this->store->id, $data['store']['id']);
        $this->assertSame(1, $data['count']);
        $this->assertSame('2026-11-10', $data['shifts'][0]['date']);
        $this->assertStringContainsString('Note '.$this->employee->id, json_encode($data));
        $this->assertStringNotContainsString('Note '.$this->colleague->id, json_encode($data));
        $this->assertTrue(ActivityEntry::query()->where('action', 'export.schedule')->where('actor_id', $this->employee->id)->exists());
    }

    public function test_the_schedule_export_as_ics(): void
    {
        $this->shift($this->employee, '2026-11-10');

        $response = $this->actingWithToken($this->employee)
            ->get('/api/v1/me/schedule-export?store='.$this->store->id.'&format=ics&from=2026-11-01&to=2026-11-30')
            ->assertOk();

        $this->assertStringContainsString('text/calendar', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->streamedContent());
    }

    public function test_the_schedule_export_is_validated_and_limited_to_my_stores(): void
    {
        $other = Store::factory()->create();
        $this->actingWithToken($this->employee);

        $this->get('/api/v1/me/schedule-export')->assertStatus(422)->assertJsonValidationErrors(['store'], 'errors');
        $this->get('/api/v1/me/schedule-export?store='.$this->store->id.'&format=xml')->assertStatus(422);
        $this->get('/api/v1/me/schedule-export?store='.$this->store->id.'&from=tomorrow')->assertStatus(422);
        $this->get('/api/v1/me/schedule-export?store='.$other->id)->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_the_schedule_export_takes_no_employee_identifier(): void
    {
        $this->shift($this->colleague, '2026-11-10');

        $response = $this->actingWithToken($this->employee)
            ->get('/api/v1/me/schedule-export?store='.$this->store->id.'&user='.$this->colleague->id.'&user_id='.$this->colleague->id.'&from=2026-11-01&to=2026-11-30')
            ->assertOk();

        $this->assertSame(0, json_decode($response->streamedContent(), true)['count']);
    }

    // --- Export des données ---

    public function test_the_data_export_gives_my_data_without_secrets(): void
    {
        $this->shift($this->employee, '2026-11-10');
        $this->employee->forceFill(['two_factor_secret' => encrypt('SECRETKEY'), 'two_factor_recovery_codes' => encrypt('["recovery-1"]')])->save();
        $this->employee->createToken('Phone');

        $response = $this->actingWithToken($this->employee)->getJson('/api/v1/me/data-export')->assertOk();

        $response->assertJsonPath('identity.id', $this->employee->id)
            ->assertJsonPath('identity.email', $this->employee->email)
            ->assertJsonCount(1, 'shifts')
            ->assertJsonStructure(['generated_at', 'identity', 'profile', 'memberships', 'roles', 'shifts', 'availabilities', 'notification_preferences']);

        $content = (string) $response->getContent();
        foreach (['SECRETKEY', 'recovery-1', 'password', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        $this->assertStringNotContainsString($this->colleague->email, $content);
        $this->assertTrue(ActivityEntry::query()->where('action', 'export.personal_data')->where('actor_id', $this->employee->id)->exists());
    }

    public function test_exports_need_a_token(): void
    {
        $this->getJson('/api/v1/me/data-export')->assertStatus(401);
        $this->getJson('/api/v1/me/schedule-export?store=1')->assertStatus(401);
    }
}
