<?php

namespace Tests\Feature\Update;

use App\Models\ActivityEntry;
use App\Models\Store;
use App\Settings\AppSettings;
use App\Update\Release;
use App\Update\UpdateApplier;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class UpdateScreenTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const API = 'https://api.github.com/repos/Acme/Kintai/releases*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.version' => '1.0.0', 'kintai.update.repository' => 'Acme/Kintai', 'kintai.support.github_token' => null]);
        Cache::flush();
        $this->seedRoles();
        $this->actingAs($this->owner());
    }

    /** @return array<string, mixed> */
    private function githubRelease(string $tag, string $body = 'Notes'): array
    {
        $name = 'kintai-'.ltrim($tag, 'v');
        $base = 'https://github.com/Acme/Kintai/releases/download/'.$tag.'/';

        return [
            'tag_name' => $tag,
            'draft' => false,
            'published_at' => '2026-02-03T10:00:00Z',
            'body' => $body,
            'assets' => [
                ['name' => $name.'.zip', 'size' => 1234, 'browser_download_url' => $base.$name.'.zip'],
                ['name' => $name.'.zip.sha256', 'size' => 80, 'browser_download_url' => $base.$name.'.zip.sha256'],
            ],
        ];
    }

    private function state(string $phase, bool $swapComplete = true): UpdateState
    {
        $state = new UpdateState('unused.json');
        $state->phase = $phase;
        $state->from = '1.0.0';
        $state->to = '1.1.0';
        $state->swapComplete = $swapComplete;

        return $state;
    }

    private function applier(?UpdateState $state = null): MockInterface
    {
        return $this->mock(UpdateApplier::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('state')->andReturn($state ?? $this->state(UpdateState::IDLE));
        });
    }

    private function fakeGithub(string $tag = 'v1.1.0', string $body = 'Notes'): void
    {
        Http::fake([self::API => Http::response([$this->githubRelease($tag, $body)])]);
    }

    public function test_only_the_owner_reaches_the_screen(): void
    {
        $this->applier();

        $this->get(route('updates.index'))->assertOk()->assertSee('Updates')->assertSee('1.0.0');

        $store = Store::factory()->create();
        $this->actingAs($this->managerOf($store));
        $this->get(route('updates.index'))->assertForbidden();
        $this->get(route('updates.apply'))->assertStatus(405);
        $this->post(route('updates.apply'))->assertForbidden();
        $this->get(route('updates.finish.show'))->assertForbidden();
        $this->post(route('updates.finish'))->assertForbidden();
        Livewire::test('pages::admin.updates')->assertForbidden();

        auth()->logout();
        $this->get(route('updates.index'))->assertRedirect(route('login'));
    }

    public function test_the_menu_entry_is_for_the_owner_only(): void
    {
        $this->get(route('dashboard'))->assertSee(route('updates.index'));

        $this->actingAs($this->managerOf(Store::factory()->create()));
        $this->get(route('dashboard'))->assertDontSee(route('updates.index'));
    }

    public function test_check_shows_the_new_version_with_date_and_notes(): void
    {
        $this->applier();
        $this->fakeGithub('v1.1.0', 'Fixes and more');

        Livewire::test('pages::admin.updates')
            ->assertDontSeeHtml('data-test="update-available"')
            ->call('check')
            ->assertSet('error', '')
            ->assertSee('Version 1.1.0 is available.')
            ->assertSee('2026-02-03')
            ->assertSee('Fixes and more')
            ->assertSeeHtml('data-test="prepare-update"');
    }

    public function test_check_says_when_up_to_date(): void
    {
        $this->applier();
        $this->fakeGithub('v1.0.0');

        Livewire::test('pages::admin.updates')->call('check')->assertSee('Kintai is up to date.')->assertDontSeeHtml('data-test="prepare-update"');
    }

    public function test_check_says_when_there_is_no_release_at_all(): void
    {
        $this->applier();
        Http::fake([self::API => Http::response([])]);

        Livewire::test('pages::admin.updates')->call('check')->assertSee('Kintai is up to date.');
    }

    public function test_a_github_error_is_a_clear_message_without_trace(): void
    {
        $this->applier();
        Http::fake([self::API => Http::response('boom', 500)]);

        Livewire::test('pages::admin.updates')
            ->call('check')
            ->assertSee('GitHub answered with an error (500) while checking for updates.')
            ->assertDontSee('Stack trace')
            ->assertDontSee('UpdateException');
    }

    public function test_the_result_is_cached_and_check_bypasses_the_cache(): void
    {
        $this->applier();
        Http::fake([self::API => Http::sequence()->push([$this->githubRelease('v1.1.0')])->push([$this->githubRelease('v1.2.0')])]);

        $component = Livewire::test('pages::admin.updates')->call('check');
        Http::assertSentCount(1);

        // Un nouvel affichage lit le cache : aucun appel réseau.
        Livewire::test('pages::admin.updates')->assertSee('Version 1.1.0 is available.');
        Http::assertSentCount(1);

        // « Rechercher » contourne le cache.
        $component->call('check')->assertSee('Version 1.2.0 is available.');
        Http::assertSentCount(2);
    }

    public function test_the_cache_is_per_channel(): void
    {
        $this->applier();
        Http::fake([self::API => Http::response([$this->githubRelease('v1.1.0'), $this->githubRelease('v1.2.0-beta.1')])]);

        Livewire::test('pages::admin.updates')->call('check')->assertSee('Version 1.1.0 is available.');

        $this->app->make(AppSettings::class)->set('update.channel', 'beta');
        Livewire::test('pages::admin.updates')->assertDontSee('Version 1.1.0 is available.')->call('check')->assertSee('Version 1.2.0-beta.1 is available.');
    }

    public function test_release_notes_are_escaped(): void
    {
        $this->applier();
        $this->fakeGithub('v1.1.0', '<script>alert(1)</script> **bold**');

        $component = Livewire::test('pages::admin.updates')->call('check');
        $html = $component->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_changing_the_channel_saves_the_setting_and_is_audited(): void
    {
        $this->applier();

        Livewire::test('pages::admin.updates')
            ->assertSet('channel', 'release')
            ->set('channel', 'beta')
            ->assertSet('channel', 'beta');

        $this->assertSame('beta', $this->app->make(AppSettings::class)->updateChannel()->value);
        $entry = ActivityEntry::where('action', 'update.channel_changed')->firstOrFail();
        $this->assertSame(['from' => 'release', 'to' => 'beta'], $entry->properties);
    }

    public function test_an_invalid_channel_is_ignored(): void
    {
        $this->applier();

        Livewire::test('pages::admin.updates')->set('channel', 'nightly')->assertSet('channel', 'release');

        $this->assertSame('release', $this->app->make(AppSettings::class)->updateChannel()->value);
        $this->assertSame(0, ActivityEntry::where('action', 'update.channel_changed')->count());
    }

    public function test_prepare_stages_the_cached_release(): void
    {
        $this->fakeGithub('v1.1.0');
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::IDLE));
            $mock->shouldReceive('stage')->once()
                ->withArgs(fn (Release $release): bool => (string) $release->version === '1.1.0')
                ->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'files' => 42, 'bytes' => 1000]);
        });

        Livewire::test('pages::admin.updates')->call('check')->call('prepare')->assertSet('error', '')->assertSet('stagedFiles', 42);
    }

    public function test_prepare_without_a_search_is_refused(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::IDLE));
            $mock->shouldReceive('stage')->never();
        });

        Livewire::test('pages::admin.updates')->call('prepare')->assertSee('No update to prepare. Check for updates first.');
    }

    public function test_prepare_shows_the_engine_error(): void
    {
        $this->fakeGithub('v1.1.0');
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::IDLE));
            $mock->shouldReceive('stage')->andThrow(new UpdateException('Not enough free disk space.'));
        });

        Livewire::test('pages::admin.updates')->call('check')->call('prepare')->assertSet('error', 'Not enough free disk space.')->assertSee('Not enough free disk space.');
    }

    public function test_a_staged_update_shows_its_summary_and_can_be_cancelled(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::STAGED));
            $mock->shouldReceive('cancel')->once();
        });

        Livewire::test('pages::admin.updates')
            ->assertSeeHtml('data-test="update-staged"')
            ->assertSee('From 1.0.0 to 1.1.0.')
            ->assertSeeHtml(route('updates.apply'))
            ->call('cancel')
            ->assertSet('error', '');

        $this->assertSame(1, ActivityEntry::where('action', 'update.cancelled')->count());
    }

    public function test_cancel_error_is_shown(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::STAGED));
            $mock->shouldReceive('cancel')->andThrow(new UpdateException('There is no prepared update to cancel.'));
        });

        Livewire::test('pages::admin.updates')->call('cancel')->assertSee('There is no prepared update to cancel.');
        $this->assertSame(0, ActivityEntry::where('action', 'update.cancelled')->count());
    }

    public function test_an_unfinished_update_shows_the_alert_and_can_be_rolled_back(): void
    {
        $state = $this->state(UpdateState::SWAPPED);
        $state->error = ['The migrations failed.'];

        $this->mock(UpdateApplier::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('state')->andReturn($state);
            $mock->shouldReceive('rollback')->once();
        });

        Livewire::test('pages::admin.updates')
            ->assertSeeHtml('data-test="update-unfinished"')
            ->assertSee('An update is unfinished.')
            ->assertSee('The migrations failed.')
            ->assertSeeHtml('data-test="finish-update"')
            ->assertSeeHtml('data-test="rollback-update"')
            ->call('rollback');

        $this->assertSame(1, ActivityEntry::where('action', 'update.rolled_back')->count());
    }

    public function test_an_interrupted_swap_offers_only_the_rollback(): void
    {
        $this->applier($this->state(UpdateState::SWAPPED, swapComplete: false));

        Livewire::test('pages::admin.updates')->assertDontSeeHtml('data-test="finish-update"')->assertSeeHtml('data-test="rollback-update"');
    }

    public function test_a_failed_rollback_shows_the_alert(): void
    {
        $state = $this->state(UpdateState::FAILED);
        $state->error = ['The file x could not be put back.'];
        $this->applier($state);

        Livewire::test('pages::admin.updates')
            ->assertSee('The rollback of the update is incomplete.')
            ->assertSee('The file x could not be put back.')
            ->assertSeeHtml('data-test="rollback-update"');
    }

    public function test_rollback_error_is_shown(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::SWAPPED));
            $mock->shouldReceive('rollback')->andThrow(new UpdateException('Another update is already running.'));
        });

        Livewire::test('pages::admin.updates')->call('rollback')->assertSee('Another update is already running.');
    }

    public function test_the_done_phase_shows_the_versions(): void
    {
        $this->applier($this->state(UpdateState::DONE));

        Livewire::test('pages::admin.updates')->assertSeeHtml('data-test="update-done"')->assertSee('Kintai was updated from 1.0.0 to 1.1.0.');
    }

    public function test_flashed_messages_are_displayed(): void
    {
        $this->applier();

        $this->withSession(['update_error' => 'Something failed.'])->get(route('updates.index'))->assertSee('Something failed.');
        $this->withSession(['update_success' => 'All good.'])->get(route('updates.index'))->assertSee('All good.');
    }

    public function test_a_damaged_state_file_is_a_message_not_a_crash(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andThrow(new UpdateException('The update state file is damaged.'));
        });

        $this->get(route('updates.index'))->assertOk()->assertSee('The update state file is damaged.');
    }
}
