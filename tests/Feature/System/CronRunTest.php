<?php

namespace Tests\Feature\System;

use App\Models\CronToken;
use App\Models\ScheduledTaskRun;
use App\Settings\AppSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CronRunTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CronToken::query()->create(['name' => 'Online cron', 'token_hash' => CronToken::hash(self::TOKEN)]);
    }

    /**
     * Une tâche planifiée de test, due à chaque minute, qui incrémente un compteur.
     */
    private function scheduleProbe(): void
    {
        $this->app->make(Schedule::class)->call(function (): void {
            DB::table('app_settings')->insert(['key' => 'probe.'.uniqid(), 'value' => '1', 'updated_at' => now()]);
        })->everyMinute()->name('probe');
    }

    private function probeRuns(): int
    {
        return DB::table('app_settings')->where('key', 'like', 'probe.%')->count();
    }

    public function test_a_valid_bearer_token_runs_the_scheduler(): void
    {
        $this->scheduleProbe();

        $this->getJson('/cron/run', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(1, $this->probeRuns());
        $this->assertNotNull(CronToken::query()->firstOrFail()->last_used_at);
    }

    public function test_a_token_in_the_query_string_works_and_post_is_accepted(): void
    {
        $this->scheduleProbe();

        $this->get('/cron/run?token='.self::TOKEN)->assertOk()->assertJsonPath('status', 'ok');
        $this->post('/cron/run?token='.self::TOKEN)->assertOk();
    }

    public function test_every_invalid_token_gets_the_same_response(): void
    {
        $responses = [
            $this->get('/cron/run'),
            $this->get('/cron/run?token=nope'),
            $this->get('/cron/run', ['Authorization' => 'Bearer nope']),
            $this->get('/cron/run?token[]=a'),
            $this->get('/cron/run', ['Authorization' => 'Bearer '.str_repeat('a', 300)]),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame($responses[0]->getContent(), $response->getContent());
        }

        $this->assertNull(CronToken::query()->firstOrFail()->last_used_at);
    }

    public function test_a_revoked_token_stops_working(): void
    {
        CronToken::query()->delete();

        $this->get('/cron/run', ['Authorization' => 'Bearer '.self::TOKEN])->assertNotFound();
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $row = DB::table('cron_tokens')->first();

        $this->assertSame(hash('sha256', self::TOKEN), $row->token_hash);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($row));
    }

    public function test_a_second_simultaneous_run_is_refused(): void
    {
        $this->scheduleProbe();
        $lock = Cache::lock('kintai.cron.run', 60);
        $this->assertTrue($lock->get());

        $this->get('/cron/run?token='.self::TOKEN)->assertStatus(409)->assertJsonPath('status', 'busy');
        $this->assertSame(0, $this->probeRuns());

        $lock->release();
        $this->get('/cron/run?token='.self::TOKEN)->assertOk();
        $this->assertSame(1, $this->probeRuns());
    }

    public function test_the_lock_is_released_after_a_run(): void
    {
        $this->get('/cron/run?token='.self::TOKEN)->assertOk();
        $this->get('/cron/run?token='.self::TOKEN)->assertOk();
    }

    public function test_no_session_and_no_cookie_are_involved(): void
    {
        $response = $this->get('/cron/run?token='.self::TOKEN);

        $response->assertOk();
        $response->assertHeaderMissing('Set-Cookie');
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_the_route_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/cron/run?token=wrong')->assertNotFound();
        }

        $this->get('/cron/run?token=wrong')->assertStatus(429);
        $this->get('/cron/run?token='.self::TOKEN)->assertStatus(429);
    }

    public function test_it_works_during_maintenance_mode(): void
    {
        $this->app->make(AppSettings::class)->set('maintenance.enabled', true);

        $this->get('/cron/run?token='.self::TOKEN)->assertOk();
    }

    public function test_the_runs_are_recorded_by_the_scheduler_events(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->call(fn () => null)->everyMinute()->name('probe-ok');
        $schedule->call(function (): void {
            throw new \RuntimeException('Something broke');
        })->everyMinute()->name('probe-ko');

        Artisan::call('schedule:run');

        $ok = ScheduledTaskRun::query()->find('probe-ok');
        $this->assertNotNull($ok?->last_success_at);
        $this->assertNull($ok->last_failure_at);

        $ko = ScheduledTaskRun::query()->find('probe-ko');
        $this->assertNotNull($ko?->last_failure_at);
        $this->assertSame('Something broke', $ko->last_error);
        $this->assertNull($ko->last_success_at);
    }

    public function test_the_core_tasks_have_stable_names(): void
    {
        $names = collect($this->app->make(Schedule::class)->events())->map(fn ($event) => $event->description)->all();

        foreach (['kintai:notifications:purge', 'kintai:activity:purge', 'kintai:backup:run', 'kintai:system:alerts', 'kintai:docs:sync'] as $name) {
            $this->assertContains($name, $names);
        }
    }
}
