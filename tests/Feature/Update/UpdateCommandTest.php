<?php

namespace Tests\Feature\Update;

use App\Models\ActivityEntry;
use App\Settings\AppSettings;
use App\Update\FinishProcess;
use App\Update\UpdateApplier;
use App\Update\UpdateException;
use App\Update\UpdateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.github.com/repos/Acme/Kintai/releases*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kintai.version' => '1.0.0', 'kintai.update.repository' => 'Acme/Kintai', 'kintai.support.github_token' => null]);
        Cache::flush();
    }

    /** @param  list<string>  $tags */
    private function fakeGithub(array $tags): void
    {
        $releases = array_map(function (string $tag): array {
            $name = 'kintai-'.ltrim($tag, 'v');
            $base = 'https://github.com/Acme/Kintai/releases/download/'.$tag.'/';

            return [
                'tag_name' => $tag, 'draft' => false, 'published_at' => '2026-02-03T10:00:00Z', 'body' => 'Notes',
                'assets' => [
                    ['name' => $name.'.zip', 'size' => 1234, 'browser_download_url' => $base.$name.'.zip'],
                    ['name' => $name.'.zip.sha256', 'size' => 80, 'browser_download_url' => $base.$name.'.zip.sha256'],
                ],
            ];
        }, $tags);

        Http::fake([self::API => Http::response($releases)]);
    }

    private function state(string $phase): UpdateState
    {
        $state = new UpdateState('unused.json');
        $state->phase = $phase;
        $state->from = '1.0.0';
        $state->to = '1.1.0';

        return $state;
    }

    public function test_check_shows_the_versions_and_the_channel_without_updating(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, fn (MockInterface $mock) => $mock->shouldReceive('stage')->never());

        $this->artisan('kintai:update', ['--check' => true])
            ->expectsOutputToContain('Installed version: 1.0.0')
            ->expectsOutputToContain('Update channel: release')
            ->expectsOutputToContain('Latest version: 1.1.0')
            ->expectsOutputToContain('Version 1.1.0 is available.')
            ->assertExitCode(0);
    }

    public function test_check_when_up_to_date(): void
    {
        $this->fakeGithub(['v1.0.0']);

        $this->artisan('kintai:update', ['--check' => true])->expectsOutputToContain('Kintai is up to date.')->assertExitCode(0);
    }

    public function test_the_channel_option_overrides_the_setting_for_one_run(): void
    {
        $this->fakeGithub(['v1.0.1', 'v1.1.0-beta.1']);

        $this->artisan('kintai:update', ['--check' => true, '--channel' => 'beta'])
            ->expectsOutputToContain('Update channel: beta')
            ->expectsOutputToContain('Latest version: 1.1.0-beta.1')
            ->assertExitCode(0);

        $this->assertSame('release', $this->app->make(AppSettings::class)->updateChannel()->value);
    }

    public function test_an_unknown_channel_is_an_error(): void
    {
        $this->artisan('kintai:update', ['--check' => true, '--channel' => 'nightly'])->expectsOutputToContain('Unknown channel.')->assertExitCode(1);
    }

    public function test_a_github_error_gives_exit_code_1(): void
    {
        Http::fake([self::API => Http::response('boom', 500)]);

        $this->artisan('kintai:update', ['--check' => true])->expectsOutputToContain('GitHub answered with an error (500)')->assertExitCode(1);
    }

    public function test_the_full_flow_stages_applies_then_finishes_in_a_new_process(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $calls = [];

        $this->mock(UpdateApplier::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('stage')->once()->andReturnUsing(function () use (&$calls): array {
                $calls[] = 'stage';

                return ['from' => '1.0.0', 'to' => '1.1.0', 'files' => 12, 'bytes' => 1];
            });
            $mock->shouldReceive('apply')->once()->andReturnUsing(function () use (&$calls): void {
                $calls[] = 'apply';
                $this->assertSame(1, ActivityEntry::where('action', 'update.applied')->count());
            });
            $mock->shouldReceive('finish')->never();
        });
        $this->mock(FinishProcess::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('prepare')->once()->andReturnUsing(function () use (&$calls): void {
                $calls[] = 'prepare';
            });
            $mock->shouldReceive('manualCommand')->andReturn('php artisan kintai:update --finish');
            $mock->shouldReceive('run')->once()->andReturnUsing(function (callable $output) use (&$calls): int {
                $calls[] = 'process';
                $output("Kintai was updated from 1.0.0 to 1.1.0.\n");

                return 0;
            });
        });

        $this->artisan('kintai:update', ['--yes' => true])
            ->expectsOutputToContain('Update prepared: 12 files.')
            ->expectsOutputToContain('Kintai was updated from 1.0.0 to 1.1.0.')
            ->expectsOutputToContain('The update was applied.')
            ->assertExitCode(0);

        $this->assertSame(['stage', 'prepare', 'apply', 'process'], $calls);

        $entry = ActivityEntry::where('action', 'update.applied')->firstOrFail();
        $this->assertNull($entry->actor_id);
        $this->assertSame(['from' => '1.0.0', 'to' => '1.1.0'], $entry->properties);
    }

    public function test_the_confirmation_can_be_declined(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, fn (MockInterface $mock) => $mock->shouldReceive('stage')->never());

        $this->artisan('kintai:update')
            ->expectsConfirmation('Update Kintai from 1.0.0 to 1.1.0?', 'no')
            ->expectsOutputToContain('Update cancelled.')
            ->assertExitCode(0);
    }

    public function test_the_confirmation_can_be_accepted(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stage')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'files' => 3, 'bytes' => 1]);
            $mock->shouldReceive('apply')->once();
        });
        $this->mock(FinishProcess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->once();
            $mock->shouldReceive('manualCommand')->andReturn('php artisan kintai:update --finish');
            $mock->shouldReceive('run')->once()->andReturn(0);
        });

        $this->artisan('kintai:update')->expectsConfirmation('Update Kintai from 1.0.0 to 1.1.0?', 'yes')->assertExitCode(0);
    }

    public function test_without_a_subprocess_the_manual_command_is_shown(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stage')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'files' => 3, 'bytes' => 1]);
            $mock->shouldReceive('apply')->once();
        });
        $this->mock(FinishProcess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->once();
            $mock->shouldReceive('manualCommand')->andReturn('php artisan kintai:update --finish');
            $mock->shouldReceive('run')->once()->andReturn(null);
        });

        $this->artisan('kintai:update', ['--yes' => true])
            ->expectsOutputToContain('Finish the update by hand with: php artisan kintai:update --finish')
            ->assertExitCode(1);
    }

    public function test_a_failing_subprocess_gives_exit_code_1(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stage')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'files' => 3, 'bytes' => 1]);
            $mock->shouldReceive('apply')->once();
        });
        $this->mock(FinishProcess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->once();
            $mock->shouldReceive('manualCommand')->andReturn('php artisan kintai:update --finish');
            $mock->shouldReceive('run')->once()->andReturn(1);
        });

        $this->artisan('kintai:update', ['--yes' => true])->expectsOutputToContain('The update could not be finished.')->assertExitCode(1);
    }

    public function test_a_stage_error_gives_exit_code_1_without_applying(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stage')->once()->andThrow(new UpdateException('Not enough free disk space.'));
            $mock->shouldReceive('apply')->never();
        });

        $this->artisan('kintai:update', ['--yes' => true])->expectsOutputToContain('Not enough free disk space.')->assertExitCode(1);
        $this->assertSame(0, ActivityEntry::where('action', 'update.applied')->count());
    }

    public function test_an_apply_error_is_audited_as_rolled_back_and_gives_exit_code_1(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stage')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'files' => 3, 'bytes' => 1]);
            $mock->shouldReceive('apply')->once()->andThrow(new UpdateException('The replacement of the files failed and was rolled back.'));
        });
        $this->mock(FinishProcess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->once();
            $mock->shouldReceive('manualCommand')->andReturn('php artisan kintai:update --finish');
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('kintai:update', ['--yes' => true])->expectsOutputToContain('The replacement of the files failed')->assertExitCode(1);
        $this->assertSame(1, ActivityEntry::where('action', 'update.rolled_back')->count());
    }

    public function test_rollback(): void
    {
        $phases = [$this->state(UpdateState::SWAPPED), $this->state(UpdateState::ROLLED_BACK)];
        $this->mock(UpdateApplier::class, function (MockInterface $mock) use ($phases): void {
            $mock->shouldReceive('state')->andReturn(...$phases);
            $mock->shouldReceive('rollback')->once();
        });

        $this->artisan('kintai:update', ['--rollback' => true])->expectsOutputToContain('The update was rolled back.')->assertExitCode(0);

        $entry = ActivityEntry::where('action', 'update.rolled_back')->firstOrFail();
        $this->assertNull($entry->actor_id);
        $this->assertTrue($entry->properties['complete']);
    }

    public function test_an_incomplete_rollback_gives_exit_code_1(): void
    {
        $failed = $this->state(UpdateState::FAILED);
        $failed->error = ['The file x could not be put back.'];
        $this->mock(UpdateApplier::class, function (MockInterface $mock) use ($failed): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::SWAPPED), $failed);
            $mock->shouldReceive('rollback')->once();
        });

        $this->artisan('kintai:update', ['--rollback' => true])
            ->expectsOutputToContain('The file x could not be put back.')
            ->expectsOutputToContain('The rollback is incomplete.')
            ->assertExitCode(1);
    }

    public function test_a_rollback_error_gives_exit_code_1(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::STAGED));
            $mock->shouldReceive('rollback')->andThrow(new UpdateException('There is no update to roll back.'));
        });

        $this->artisan('kintai:update', ['--rollback' => true])->expectsOutputToContain('There is no update to roll back.')->assertExitCode(1);
    }

    public function test_finish(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::SWAPPED));
            $mock->shouldReceive('finish')->once()->andReturn(['from' => '1.0.0', 'to' => '1.1.0', 'migrations_output' => 'Nothing to migrate.']);
        });

        $this->artisan('kintai:update', ['--finish' => true])
            ->expectsOutputToContain('Nothing to migrate.')
            ->expectsOutputToContain('Kintai was updated from 1.0.0 to 1.1.0.')
            ->assertExitCode(0);
    }

    public function test_a_finish_error_is_audited_and_gives_exit_code_1(): void
    {
        $this->mock(UpdateApplier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->andReturn($this->state(UpdateState::SWAPPED));
            $mock->shouldReceive('finish')->once()->andThrow(new UpdateException('The update failed and was rolled back. The migrations failed.'));
        });

        $this->artisan('kintai:update', ['--finish' => true])->expectsOutputToContain('The migrations failed.')->assertExitCode(1);
        $this->assertSame(1, ActivityEntry::where('action', 'update.rolled_back')->count());
    }
}
