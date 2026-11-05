<?php

namespace Tests\Feature\System;

use App\Models\ActivityEntry;
use App\Models\CronToken;
use App\Models\ScheduledTaskRun;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ScheduledTasksScreenTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
    }

    public function test_only_the_owner_reaches_the_screen(): void
    {
        $this->get(route('scheduled-tasks.index'))->assertOk()->assertSee('Scheduled tasks');

        $this->actingAs($this->managerOf(Store::factory()->create()));
        $this->get(route('scheduled-tasks.index'))->assertForbidden();
        Livewire::test('pages::admin.scheduled-tasks')->assertForbidden();
    }

    public function test_the_menu_entry_is_for_the_owner_only(): void
    {
        $this->get(route('dashboard'))->assertSee(route('scheduled-tasks.index'));

        $this->actingAs($this->managerOf(Store::factory()->create()));
        $this->get(route('dashboard'))->assertDontSee(route('scheduled-tasks.index'));
    }

    public function test_the_tasks_are_listed_with_their_frequency_and_last_result(): void
    {
        ScheduledTaskRun::query()->create(['name' => 'kintai:backup:run', 'last_success_at' => now()->subHour()]);
        ScheduledTaskRun::query()->create(['name' => 'kintai:system:alerts', 'last_success_at' => now()->subDay(), 'last_failure_at' => now()->subMinute(), 'last_error' => 'Boom']);

        Livewire::test('pages::admin.scheduled-tasks')
            ->assertSee('kintai:backup:run')
            ->assertSee('0 3 * * *')
            ->assertSee('kintai:system:alerts')
            ->assertSee('0 * * * *')
            ->assertSeeHtml('data-test="task-succeeded"')
            ->assertSeeHtml('data-test="task-failed"')
            ->assertSee('Boom')
            ->assertSeeHtml('data-test="task-never"');
    }

    public function test_the_setup_section_documents_the_cron_and_the_web_address(): void
    {
        Livewire::test('pages::admin.scheduled-tasks')
            ->assertSee('schedule:run')
            ->assertSeeHtml('data-test="cron-endpoint"')
            ->assertSee(url('/cron/run'))
            ->assertSee('Authorization: Bearer YOUR_TOKEN');
    }

    public function test_a_token_is_created_shown_once_and_stored_hashed(): void
    {
        $component = Livewire::test('pages::admin.scheduled-tasks')
            ->set('name', 'Online cron')
            ->call('createToken')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-test="new-token-value"');

        $token = $component->get('newToken');
        $this->assertSame(64, strlen($token));

        $row = CronToken::query()->firstOrFail();
        $this->assertSame('Online cron', $row->name);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame($this->owner->id, $row->created_by);
        $component->assertSee('Authorization: Bearer '.$token)->assertSee('?token='.$token);

        // Fermé, le jeton n'est plus montré ni retrouvable.
        $component->call('dismissToken')->assertSet('newToken', '')->assertDontSee($token);
        Livewire::test('pages::admin.scheduled-tasks')->assertDontSee($token);

        $entry = ActivityEntry::query()->where('action', 'cron.token_created')->firstOrFail();
        $this->assertStringNotContainsString($token, json_encode($entry->properties));
    }

    public function test_the_token_name_is_required_and_limited(): void
    {
        Livewire::test('pages::admin.scheduled-tasks')->set('name', '  ')->call('createToken')->assertHasErrors('name');
        Livewire::test('pages::admin.scheduled-tasks')->set('name', str_repeat('a', 101))->call('createToken')->assertHasErrors('name');

        $this->assertSame(0, CronToken::query()->count());
    }

    public function test_a_token_can_be_revoked_and_then_stops_working(): void
    {
        $plain = Livewire::test('pages::admin.scheduled-tasks')->set('name', 'Hosting')->call('createToken')->get('newToken');
        $row = CronToken::query()->firstOrFail();

        $this->get('/cron/run?token='.$plain)->assertOk();

        Livewire::test('pages::admin.scheduled-tasks')
            ->call('confirmRevoke', $row->id)
            ->assertSet('revokeId', $row->id)
            ->call('revokePending')
            ->assertSet('revokeId', 0);

        $this->assertSame(0, CronToken::query()->count());
        $this->get('/cron/run?token='.$plain)->assertNotFound();
        $this->assertTrue(ActivityEntry::query()->where('action', 'cron.token_revoked')->exists());
    }

    public function test_the_token_cannot_be_forged_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pages::admin.scheduled-tasks')->set('newToken', 'forged');
    }
}
