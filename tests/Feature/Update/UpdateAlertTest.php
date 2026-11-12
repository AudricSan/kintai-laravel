<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Settings\AppSettings;
use App\System\CoreSystemAlerts;
use App\System\SystemAlerts;
use App\Update\UpdateChannel;
use App\Update\UpdateChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kintai\Contracts\System\SystemAlert;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class UpdateAlertTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const API = 'https://api.github.com/repos/Acme/Kintai/releases*';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        config(['kintai.version' => '1.0.0', 'kintai.update.repository' => 'Acme/Kintai', 'kintai.support.github_token' => null]);
        Cache::flush();
        $this->seedRoles();
        $this->owner = $this->owner();
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

    private function detect(): ?SystemAlert
    {
        return $this->app->make(CoreSystemAlerts::class)->coreUpdateAvailable();
    }

    public function test_no_alert_without_cache(): void
    {
        Http::preventStrayRequests();

        $this->assertNull($this->detect());
        Http::assertNothingSent();
    }

    public function test_an_alert_is_raised_for_a_newer_cached_release_without_any_http_call(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->app->make(UpdateChecker::class)->check(UpdateChannel::Release);

        Http::preventStrayRequests();
        Http::fake();

        $alert = $this->detect();

        $this->assertNotNull($alert);
        $this->assertSame('1.1.0', $alert->key);
        $this->assertSame(CoreSystemAlerts::BODIES['core_update_available'], $alert->body);
        $this->assertSame(['version' => '1.1.0', 'current' => '1.0.0'], $alert->params);
        $this->assertSame(route('updates.index', [], false), $alert->link);
        Http::assertNothingSent();
    }

    public function test_no_alert_for_the_same_or_an_older_version_or_no_release(): void
    {
        $checker = $this->app->make(UpdateChecker::class);

        $this->fakeGithub(['v1.0.0']);
        $checker->check(UpdateChannel::Release);
        $this->assertNull($this->detect());

        config(['kintai.version' => '2.0.0']);
        $this->assertNull($this->detect());

        Http::fake([self::API => Http::response([])]);
        $checker->check(UpdateChannel::Release);
        $this->assertNull($this->detect());
    }

    public function test_the_alert_follows_the_configured_channel(): void
    {
        $this->fakeGithub(['v1.1.0-beta.1']);
        $this->app->make(UpdateChecker::class)->check(UpdateChannel::Beta);

        $this->assertNull($this->detect());

        $this->app->make(AppSettings::class)->set('update.channel', 'beta');
        $this->assertSame('1.1.0-beta.1', $this->detect()?->key);
    }

    public function test_the_refresh_command_fills_the_cache_for_ninety_minutes(): void
    {
        $this->fakeGithub(['v1.1.0']);

        $this->artisan('kintai:update:refresh')->assertSuccessful();

        $this->assertSame('1.1.0', $this->detect()?->key);

        Carbon::setTestNow(now()->addMinutes(UpdateChecker::CACHE_MINUTES + 5));
        $this->assertNotNull($this->detect());

        Carbon::setTestNow(now()->addMinutes(UpdateChecker::REFRESH_CACHE_MINUTES));
        $this->assertNull($this->detect());
    }

    public function test_the_refresh_command_survives_a_network_error(): void
    {
        Http::fake([self::API => Http::response('boom', 500)]);
        Log::spy();

        $this->artisan('kintai:update:refresh')->assertSuccessful();

        Log::shouldHaveReceived('warning')->once();
        $this->assertNull($this->detect());
    }

    public function test_the_screen_check_keeps_its_fifteen_minute_cache(): void
    {
        $this->fakeGithub(['v1.1.0']);
        $this->app->make(UpdateChecker::class)->check(UpdateChannel::Release);

        Carbon::setTestNow(now()->addMinutes(UpdateChecker::CACHE_MINUTES + 1));

        $this->assertNull($this->app->make(UpdateChecker::class)->cached(UpdateChannel::Release));
    }

    public function test_the_owner_is_notified_once_and_the_refresh_is_scheduled_before_the_alerts(): void
    {
        Notification::fake();
        $this->fakeGithub(['v1.1.0']);
        $this->artisan('kintai:update:refresh')->assertSuccessful();

        $this->assertContains('core_update_available', $this->app->make(SystemAlerts::class)->run());
        Notification::assertSentTo($this->owner, KintaiNotification::class);

        $this->assertNotContains('core_update_available', $this->app->make(SystemAlerts::class)->run());

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'kintai:update:refresh'));
        $this->assertNotNull($event);
        $this->assertSame('50 * * * *', $event->expression);
    }
}
