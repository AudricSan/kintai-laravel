<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\PurgeNotifications;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationInbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => null]);
    }

    private function notify(?User $user = null, ?string $link = '/my-shifts/day?date=2026-11-02', ?int $reference = 5, string $type = 'shift_assigned'): DatabaseNotification
    {
        $user ??= $this->user;
        $known = $user->notifications()->pluck('id')->all();

        $user->notify(KintaiNotification::make($type, 'A shift was added for you on :date from :start to :end (:store).', ['date' => '2026-11-02', 'start' => '09:00', 'end' => '17:00', 'store' => 'Alpha'], $link, $reference));

        return $user->notifications()->whereNotIn('id', $known)->firstOrFail();
    }

    private function storeRaw(string $link): DatabaseNotification
    {
        $notification = $this->notify();
        $notification->forceFill(['data' => array_merge($notification->data, ['link' => $link])])->save();

        return $notification;
    }

    public function test_the_bell_shows_the_unread_count_and_the_latest_ten(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->notify();
        }
        $this->notify(User::factory()->create());

        $component = Livewire::actingAs($this->user)->test('notification-bell');

        $component->assertSeeHtml('data-test="bell-count"')->assertSee('12');
        $this->assertCount(10, $component->viewData('items'));
        $this->assertSame(12, $component->viewData('count'));
    }

    public function test_the_bell_polls_every_fifteen_seconds_and_is_in_the_layout(): void
    {
        Livewire::actingAs($this->user)->test('notification-bell')->assertSeeHtml('wire:poll.15s');

        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertSeeHtml('data-test="notification-bell"');
    }

    public function test_the_bell_marks_everything_read(): void
    {
        $this->notify();
        $this->notify();

        Livewire::actingAs($this->user)->test('notification-bell')->call('markAllRead')->assertDontSeeHtml('data-test="bell-count"');

        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    public function test_opening_marks_read_and_redirects_to_an_internal_link(): void
    {
        $notification = $this->notify();

        $this->actingAs($this->user)->get(route('notifications.open', $notification->id))->assertRedirect('/my-shifts/day?date=2026-11-02');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_opening_never_redirects_to_an_external_or_ambiguous_link(): void
    {
        foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'javascript:alert(1)', 'shifts', ''] as $link) {
            $notification = $this->storeRaw($link);

            $this->actingAs($this->user)->get(route('notifications.open', $notification->id))->assertRedirect(route('notifications.index'));
            $this->assertNotNull($notification->fresh()->read_at);
        }

        $notification = $this->storeRaw('/shifts');
        $this->actingAs($this->user)->get(route('notifications.open', $notification->id))->assertRedirect('/shifts');
    }

    public function test_a_link_is_filtered_when_the_notification_is_created(): void
    {
        $this->assertNull($this->notify(link: 'https://evil.example')->data['link']);
        $this->assertNull($this->notify(link: '//evil')->data['link']);
        $this->assertSame('/shifts', $this->notify(link: '/shifts')->data['link']);
    }

    public function test_one_cannot_open_the_notification_of_someone_else(): void
    {
        $notification = $this->notify(User::factory()->create());

        $this->actingAs($this->user)->get(route('notifications.open', $notification->id))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_opening_requires_a_login(): void
    {
        $this->get(route('notifications.open', Str::uuid()->toString()))->assertRedirect(route('login'));
    }

    public function test_the_page_is_paginated_by_twenty(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->notify();
        }

        $component = Livewire::actingAs($this->user)->test('pages::notifications.index');
        $this->assertCount(20, $component->viewData('rows'));

        $component->call('setPage', 2);
        $this->assertCount(5, $component->viewData('rows'));
    }

    public function test_the_page_marks_all_read(): void
    {
        $this->notify();
        $other = $this->notify(User::factory()->create());

        Livewire::actingAs($this->user)->test('pages::notifications.index')->call('markAllRead');

        $this->assertSame(0, $this->user->unreadNotifications()->count());
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_the_page_deletes_everything_of_the_user_only(): void
    {
        $this->notify();
        $this->notify();
        $other = $this->notify(User::factory()->create());

        Livewire::actingAs($this->user)->test('pages::notifications.index')->call('deleteAll');

        $this->assertSame(0, $this->user->notifications()->count());
        $this->assertNotNull($other->fresh());
    }

    public function test_the_page_is_titled_and_lists_the_text_in_the_reader_language(): void
    {
        $this->notify();
        $this->user->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($this->user)->get(route('notifications.index'))->assertOk()->assertSee('Nouveau shift assigné')->assertSee('Tout marquer comme lu');
    }

    public function test_the_menu_has_a_notifications_entry(): void
    {
        $this->actingAs($this->user)->get(route('dashboard.employee'))->assertOk()->assertSee(route('notifications.index'));
    }

    // « Lu » automatique

    public function test_mark_read_for_targets_the_reference_of_the_user(): void
    {
        $match = $this->notify(reference: 7);
        $otherReference = $this->notify(reference: 8);
        $otherType = $this->notify(reference: 7, type: 'shift_deleted');
        $someoneElse = $this->notify(User::factory()->create(), reference: 7);

        app(NotificationInbox::class)->markReadFor($this->user, 'shift', [7]);

        $this->assertNotNull($match->fresh()->read_at);
        $this->assertNull($otherReference->fresh()->read_at);
        $this->assertNull($otherType->fresh()->read_at); // shift_deleted désigne un jour (shift_day), pas un shift
        $this->assertNull($someoneElse->fresh()->read_at);
    }

    public function test_opening_the_day_view_marks_the_shift_and_day_notifications_read(): void
    {
        $store = Store::factory()->create();
        $this->seedRoles();
        $employee = $this->employeeOf($store);
        $shift = Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $employee->id, 'shift_date' => '2026-11-02',
            'starts_at' => Shift::localTime('2026-11-02', '09:00'), 'ends_at' => Shift::localTime('2026-11-02', '17:00'),
        ]);

        $assigned = $this->notify($employee, reference: $shift->id);
        $deleted = $this->notify($employee, reference: 20261102, type: 'shift_deleted');
        $otherDay = $this->notify($employee, reference: 20261103, type: 'shift_deleted');

        Livewire::actingAs($employee)->withQueryParams(['date' => '2026-11-02'])->test('pages::my-shifts.day');

        $this->assertNotNull($assigned->fresh()->read_at);
        $this->assertNotNull($deleted->fresh()->read_at);
        $this->assertNull($otherDay->fresh()->read_at);
    }

    // Purge

    public function test_the_purge_deletes_only_old_read_notifications(): void
    {
        $oldRead = $this->notify();
        $oldUnread = $this->notify();
        $recentRead = $this->notify();
        $oldRead->forceFill(['read_at' => now(), 'created_at' => now()->subDays(100)])->save();
        $oldUnread->forceFill(['created_at' => now()->subDays(400)])->save();
        $recentRead->forceFill(['read_at' => now()])->save();

        $this->artisan('kintai:notifications:purge')->assertSuccessful();

        $this->assertNull(DatabaseNotification::find($oldRead->id));
        $this->assertNotNull(DatabaseNotification::find($oldUnread->id));
        $this->assertNotNull(DatabaseNotification::find($recentRead->id));
    }

    public function test_the_retention_is_bounded(): void
    {
        $this->assertSame(90, PurgeNotifications::retentionDays());

        foreach ([[1, 7], [0, 90], [-5, 90], [30, 30], [999999, 3650], [3650, 3650], [7, 7]] as [$configured, $expected]) {
            config(['kintai.notifications.retention_days' => $configured]);
            $this->assertSame($expected, PurgeNotifications::retentionDays(), "configured {$configured}");
        }
    }

    public function test_the_purge_honours_the_minimum_retention(): void
    {
        config(['kintai.notifications.retention_days' => 1]);
        $tooRecent = $this->notify();
        $tooRecent->forceFill(['read_at' => now(), 'created_at' => now()->subDays(3)])->save();
        $old = $this->notify();
        $old->forceFill(['read_at' => now(), 'created_at' => now()->subDays(8)])->save();

        $this->artisan('kintai:notifications:purge')->assertSuccessful();

        $this->assertNotNull(DatabaseNotification::find($tooRecent->id));
        $this->assertNull(DatabaseNotification::find($old->id));
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'kintai:notifications:purge'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
