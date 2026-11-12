<?php

namespace Kintai\Bundles\GoogleCalendar\Tests;

use App\Actions\AnonymizeUser;
use App\Cron\ScheduledTasks;
use App\Models\CronToken;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use FilesystemIterator;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Bundles\GoogleCalendar\Models\CalendarEventLink;
use Kintai\Bundles\GoogleCalendar\Models\CalendarSyncQueueItem;
use Kintai\Contracts\Activity\ActivityActionRegistry;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\Profile\ProfileSectionRegistry;
use Kintai\Contracts\Settings\Settings;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

require_once __DIR__.'/GoogleCalendarFixtures.php';

/**
 * Branchement du bundle dans le Core : désactivé il est absent partout (sauf ses tables), activé il se déclare par les
 * registres du contrat ; tâche planifiée et `/cron/run` ; anonymisation ; le Core ne contient aucun code Google Agenda.
 */
class BundleTest extends TestCase
{
    use GoogleCalendarFixtures;
    use RefreshDatabase;

    private const CRON_TOKEN = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha']);
    }

    private function enable(): void
    {
        $this->enableBundle('google-calendar');
    }

    private function scheduledEvent(): ?ScheduledEvent
    {
        return collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'google-calendar:sync');
    }

    // -- Bundle désactivé -----------------------------------------------------------------------------------------------

    public function test_a_disabled_bundle_is_absent_everywhere_but_keeps_its_tables(): void
    {
        $owner = $this->owner();
        $employee = $this->employee();

        $this->actingAs($owner)->get('/google-calendar')->assertNotFound();
        $this->actingAs($owner)->get('/google-calendar/settings')->assertNotFound();
        $this->actingAs($employee)->get('/google-calendar/callback?state=a&code=b')->assertNotFound();

        $this->assertFalse(app(Settings::class)->has('google_calendar.enabled'));
        $this->assertFalse(app(NavigationRegistry::class)->has('google-calendar-settings'));
        $this->assertSame([], array_filter(app(ProfileSectionRegistry::class)->sections($owner), fn ($section): bool => $section->key === 'google-calendar'));
        $this->assertFalse(app(NotificationTypeRegistry::class)->has('google_calendar_revoked'));
        $this->assertNull($this->scheduledEvent());
        $this->assertSame([], array_filter(app(ScheduledTasks::class)->all(), fn (array $task): bool => $task['name'] === 'google-calendar:sync'));
        $this->assertTrue(Schema::hasTable('google_calendar_connections') && Schema::hasTable('google_calendar_event_links') && Schema::hasTable('google_calendar_sync_queue'));
    }

    public function test_a_disabled_bundle_listens_to_nothing_and_its_command_does_nothing(): void
    {
        $employee = $this->employee();
        $connection = CalendarConnection::factory()->withCalendar()->create(['user_id' => $employee->id]);
        $shift = $this->shiftFor($employee);
        $this->fakeGoogle();

        ShiftCreated::dispatch($shift->id);

        $this->assertSame(0, CalendarSyncQueueItem::count());
        $this->assertSame(0, Artisan::call('google-calendar:sync'));
        $this->assertStringContainsString('"enabled":false', Artisan::output());
        $this->assertCount(0, Http::recorded());
        $this->assertNotNull(CalendarConnection::find($connection->id));
    }

    public function test_a_disabled_bundle_does_not_clean_up_on_anonymization(): void
    {
        $employee = $this->employee();
        $connection = CalendarConnection::factory()->create(['user_id' => $employee->id]);
        $this->fakeGoogle();

        app(AnonymizeUser::class)->handle($employee);

        $this->assertNotNull(CalendarConnection::find($connection->id), 'Disabling a bundle never deletes its data.');
        $this->assertCount(0, Http::recorded());
    }

    // -- Bundle activé --------------------------------------------------------------------------------------------------

    public function test_the_enabled_bundle_declares_itself_through_the_contract_registries(): void
    {
        $this->enable();

        $this->assertTrue(app(Settings::class)->has('google_calendar.client_secret'));
        $this->assertTrue(app(NotificationTypeRegistry::class)->has('google_calendar_revoked'));
        foreach (['google_calendar.connected', 'google_calendar.disconnected', 'google_calendar.revoked', 'google_calendar.settings_updated'] as $action) {
            $this->assertTrue(app(ActivityActionRegistry::class)->has($action), $action);
        }
    }

    public function test_the_secret_setting_is_declared_encrypted(): void
    {
        $this->enable();

        $this->assertTrue(app(AppSettings::class)->definition('google_calendar.client_secret')->encrypted);
        $this->assertFalse(app(AppSettings::class)->definition('google_calendar.client_id')->encrypted);
    }

    public function test_the_menu_entry_is_for_the_owner_only(): void
    {
        $this->enable();
        $keys = fn (User $user): array => array_map(fn ($item) => $item->key, app(NavigationRegistry::class)->visibleTo($user));

        $this->assertContains('google-calendar-settings', $keys($this->owner()));
        $this->assertNotContains('google-calendar-settings', $keys($this->managerOf($this->store)));
        $this->assertNotContains('google-calendar-settings', $keys($this->employee()));
    }

    public function test_the_profile_section_follows_the_activation_of_the_feature_and_the_link(): void
    {
        $this->enable();
        $ana = $this->employee();
        $bob = $this->employee();
        $this->connection($bob);
        $has = fn (User $user): bool => collect(app(ProfileSectionRegistry::class)->sections($user))->contains(fn ($section): bool => $section->key === 'google-calendar');

        $this->assertFalse($has($ana));
        $this->assertTrue($has($bob), 'Someone already linked keeps access to unlink.');

        $this->configureGoogle();
        $this->assertTrue($has($ana));
        $this->assertSame('google-calendar.profile', collect(app(ProfileSectionRegistry::class)->sections($ana))->firstWhere('key', 'google-calendar')?->route);
    }

    // -- Tâche planifiée ------------------------------------------------------------------------------------------------

    public function test_the_sync_task_is_scheduled_every_minute_without_overlapping_and_listed_for_the_owner(): void
    {
        $this->enable();

        $event = $this->scheduledEvent();
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('google-calendar:sync', (string) $event->command);

        $tasks = collect(app(ScheduledTasks::class)->all())->firstWhere('name', 'google-calendar:sync');
        $this->assertNotNull($tasks);
        $this->assertSame('* * * * *', $tasks['expression']);
        $this->assertNotNull($tasks['next_due']);
    }

    public function test_the_cron_route_runs_the_sync_task(): void
    {
        $this->enable();
        $this->configureGoogle();
        Cache::flush();
        CronToken::query()->create(['name' => 'Online cron', 'token_hash' => CronToken::hash(self::CRON_TOKEN)]);
        $ana = $this->employee();
        $connection = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->fakeGoogle();

        // Le vrai événement lance `php artisan google-calendar:sync` dans un processus à part (comme toute commande
        // planifiée), qui ne voit pas la base mémoire des tests : on le remplace par un jumeau exécuté dans le processus,
        // construit depuis la commande réellement planifiée par le bundle.
        $real = $this->scheduledEvent();
        $this->assertNotNull($real);
        $this->assertMatchesRegularExpression('/artisan.? google-calendar:sync$/', (string) $real->command);
        $this->app->forgetInstance(Schedule::class);
        $schedule = $this->app->make(Schedule::class);
        $this->setProtectedEvents($schedule, []);
        $schedule->call(fn () => Artisan::call('google-calendar:sync'))->everyMinute()->name('google-calendar:sync');

        $finished = [];
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use (&$finished): void {
            $finished[] = $event->task->description;
        });

        $this->getJson('/cron/run', ['Authorization' => 'Bearer '.self::CRON_TOKEN])->assertOk()->assertJsonPath('status', 'ok');

        $this->assertContains('google-calendar:sync', $finished);
        $this->assertNotNull(CalendarEventLink::query()->where('connection_id', $connection->id)->where('source_key', (string) $shift->id)->first());
        $this->assertCount(1, $this->sent('POST', '/events'));
    }

    /**
     * @param  list<ScheduledEvent>  $events
     */
    private function setProtectedEvents(Schedule $schedule, array $events): void
    {
        $property = new \ReflectionProperty($schedule, 'events');
        $property->setValue($schedule, $events);
    }

    // -- Anonymisation --------------------------------------------------------------------------------------------------

    public function test_anonymizing_a_user_revokes_and_deletes_their_link_and_leaves_the_others(): void
    {
        $this->enable();
        $ana = $this->employee();
        $bob = $this->employee();
        $anaLink = $this->connection($ana, ['refresh_token' => 'ana-refresh']);
        $bobLink = $this->connection($bob);
        foreach ([$anaLink, $bobLink] as $link) {
            CalendarEventLink::create(['connection_id' => $link->id, 'source_type' => 'shift', 'source_key' => '1', 'external_event_id' => 'evt', 'content_hash' => str_repeat('a', 64)]);
            CalendarSyncQueueItem::create(['connection_id' => $link->id, 'source_type' => 'shift', 'source_key' => '1']);
        }
        $this->fakeGoogle();

        app(AnonymizeUser::class)->handle($ana);

        $this->assertNull(CalendarConnection::query()->where('user_id', $ana->id)->first());
        $this->assertSame(0, CalendarEventLink::where('connection_id', $anaLink->id)->count());
        $this->assertSame(0, CalendarSyncQueueItem::where('connection_id', $anaLink->id)->count());
        $this->assertNotNull(CalendarConnection::find($bobLink->id));
        $this->assertSame(1, CalendarEventLink::where('connection_id', $bobLink->id)->count());
        $this->assertSame(1, CalendarSyncQueueItem::where('connection_id', $bobLink->id)->count());
        $this->assertSame('ana-refresh', $this->sent('POST', 'oauth2.googleapis.com/revoke')->sole()['token']);
        $this->assertCount(0, $this->sent('DELETE', '/events/'), 'Events are not deleted: the account is going away, not the calendar.');
    }

    public function test_anonymizing_still_works_when_google_is_unreachable(): void
    {
        $this->enable();
        $ana = $this->employee();
        $this->connection($ana);
        $this->fakeNetworkDown();

        app(AnonymizeUser::class)->handle($ana);

        $this->assertSame(0, CalendarConnection::count());
    }

    // -- Le Core ne connaît pas Google Agenda -----------------------------------------------------------------------------

    public function test_the_core_and_the_contract_contain_no_google_calendar_code(): void
    {
        foreach ([base_path('app'), base_path('packages/contracts/src'), base_path('routes'), base_path('database')] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with($file->getPathname(), '.php')) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/google[ _-]?calendar|calendar[ _-]?sync|googleapis\.com\/(calendar|auth\/calendar)/i',
                    (string) file_get_contents($file->getPathname()),
                    $file->getPathname().' mentions Google Calendar.',
                );
            }
        }
    }
}
