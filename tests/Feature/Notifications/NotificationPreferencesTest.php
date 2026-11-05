<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\SaveNotificationPreferences;
use App\Actions\Notifications\SendTestNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\CoreNotificationTypes;
use App\Notifications\DefaultNotificationTypeRegistry;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    // Registre

    public function test_core_types_are_registered_and_internal_ones_stay_out_of_the_grid(): void
    {
        $registry = app(NotificationTypeRegistry::class);

        foreach (['shift_assigned', 'shift_updated', 'shift_deleted', 'notification_test'] as $key) {
            $this->assertTrue($registry->has($key));
        }

        $this->assertFalse($registry->has('calendar_sync_error'));

        $grid = $registry->grid();
        $this->assertSame(['planning'], array_keys($grid));
        $this->assertSame(['shift_assigned', 'shift_updated', 'shift_deleted'], array_map(fn (NotificationType $t): string => $t->key, $grid['planning']));
    }

    public function test_a_type_is_validated(): void
    {
        foreach (['Bad', 'a', '1abc', 'with-dash', str_repeat('a', 62)] as $key) {
            try {
                new NotificationType($key, 'Label');
                $this->fail("Key [{$key}] should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new NotificationType('valid_key', 'Label', 'unknown-category');
    }

    public function test_a_reference_type_is_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationType('valid_key', 'Label', 'planning', 'bell', referenceType: 'Bad Ref');
    }

    public function test_duplicates_are_rejected_and_bundles_can_register(): void
    {
        $registry = new DefaultNotificationTypeRegistry;
        $registry->register(new NotificationType('timeoff_approved', 'Time off approved', 'requests', 'sun', mail: true));

        $this->assertSame(['requests'], array_keys($registry->grid()));

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new NotificationType('timeoff_approved', 'Again'));
    }

    // Préférences

    public function test_defaults_apply_without_any_stored_row(): void
    {
        $user = User::factory()->create();
        $preferences = app(NotificationPreferences::class);

        $this->assertTrue($preferences->isEnabled($user, 'shift_assigned', 'mail'));
        $this->assertTrue($preferences->isEnabled($user, 'shift_updated', 'push'));
        $this->assertFalse($preferences->isEnabled($user, 'shift_updated', 'mail'));
        $this->assertFalse($preferences->isEnabled($user, 'unknown_type', 'mail'));
        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_only_deviations_from_the_default_are_stored(): void
    {
        $user = User::factory()->create();
        $preferences = app(NotificationPreferences::class);

        $preferences->set($user, 'shift_assigned', 'mail', false);
        $preferences->set($user, 'shift_updated', 'mail', true);
        $preferences->set($user, 'shift_updated', 'push', true); // = défaut : aucune ligne

        $this->assertSame(2, NotificationPreference::count());
        $this->assertFalse($preferences->isEnabled($user, 'shift_assigned', 'mail'));
        $this->assertTrue($preferences->isEnabled($user, 'shift_updated', 'mail'));

        $preferences->set($user, 'shift_assigned', 'mail', true); // retour au défaut : la ligne disparaît
        $this->assertSame(1, NotificationPreference::count());
    }

    public function test_internal_unknown_types_and_unknown_channels_are_ignored(): void
    {
        $user = User::factory()->create();
        $preferences = app(NotificationPreferences::class);

        $preferences->set($user, 'notification_test', 'mail', false);
        $preferences->set($user, 'nope', 'mail', true);
        $preferences->set($user, 'shift_assigned', 'sms', true);

        $this->assertSame(0, NotificationPreference::count());
        $this->assertTrue($preferences->isEnabled($user, 'notification_test', 'mail'));
    }

    public function test_via_follows_the_preferences_and_the_bell_is_always_on(): void
    {
        $user = User::factory()->create(['email' => 'emma@example.test']);
        $notification = KintaiNotification::make('shift_assigned', 'A shift was added for you on :date from :start to :end (:store).');

        $this->assertSame(['database', 'mail'], $notification->via($user));

        app(NotificationPreferences::class)->set($user, 'shift_assigned', 'mail', false);
        $this->assertSame(['database'], $notification->via($user));

        $noMail = User::factory()->create(['email' => null]);
        $this->assertSame(['database'], $notification->via($noMail));
    }

    public function test_the_save_action_stores_deviations_and_ignores_unknown_keys(): void
    {
        $user = User::factory()->create();

        app(SaveNotificationPreferences::class)->handle($user, [
            'shift_assigned' => ['push'],            // mail décoché
            'shift_updated' => ['push', 'mail'],     // mail coché
            'shift_deleted' => ['push', 'mail'],     // = défaut
            'unknown' => ['push', 'mail'],           // ignoré
            'notification_test' => [],               // interne : ignoré
        ], false, null, null);

        $preferences = app(NotificationPreferences::class);
        $this->assertFalse($preferences->isEnabled($user, 'shift_assigned', 'mail'));
        $this->assertTrue($preferences->isEnabled($user, 'shift_updated', 'mail'));
        $this->assertSame(2, NotificationPreference::count());
        $this->assertTrue($preferences->isEnabled($user, 'notification_test', 'mail'));
    }

    // Heures silencieuses

    public function test_quiet_hours_are_validated_and_stored(): void
    {
        $user = User::factory()->create();
        $action = app(SaveNotificationPreferences::class);

        $action->handle($user, [], true, '22:00', '07:00');
        $user->refresh();
        $this->assertSame('22:00', $user->quiet_hours_start);
        $this->assertSame('07:00', $user->quiet_hours_end);

        foreach ([['25:00', '07:00'], ['22:00', 'abc'], ['08:00', '08:00'], [null, '07:00']] as [$start, $end]) {
            try {
                $action->handle($user, [], true, $start, $end);
                $this->fail('Quiet hours should be rejected.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('22:00', $user->fresh()->quiet_hours_start);

        $action->handle($user, [], false, '22:00', '07:00');
        $this->assertNull($user->fresh()->quiet_hours_start);
        $this->assertFalse(app(NotificationPreferences::class)->quietHours($user->fresh())['enabled']);
    }

    public function test_quiet_hours_can_span_midnight(): void
    {
        config(['kintai.timezone' => 'Asia/Tokyo']);
        $user = User::factory()->create(['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $preferences = app(NotificationPreferences::class);

        // 14:00 UTC = 23:00 à Tokyo ; 00:00 UTC = 09:00 à Tokyo.
        $this->assertTrue($preferences->isQuietNow($user, CarbonImmutable::parse('2026-11-02 14:00', 'UTC')));
        $this->assertTrue($preferences->isQuietNow($user, CarbonImmutable::parse('2026-11-02 21:30', 'UTC'))); // 06:30
        $this->assertFalse($preferences->isQuietNow($user, CarbonImmutable::parse('2026-11-02 00:00', 'UTC')));
        $this->assertFalse($preferences->isQuietNow(User::factory()->create(), CarbonImmutable::parse('2026-11-02 14:00', 'UTC')));
    }

    // Page et test d'envoi

    public function test_the_settings_page_saves_the_grid_and_quiet_hours(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.notifications')
            ->assertSet('channels.shift_assigned', ['push', 'mail'])
            ->assertSet('channels.shift_updated', ['push'])
            ->set('channels.shift_assigned', ['push'])
            ->set('quietEnabled', true)
            ->set('quietStart', '23:00')
            ->set('quietEnd', '06:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(app(NotificationPreferences::class)->isEnabled($user, 'shift_assigned', 'mail'));
        $this->assertSame('23:00', $user->fresh()->quiet_hours_start);

        Livewire::actingAs($user)->test('pages::settings.notifications')
            ->set('quietEnabled', true)
            ->set('quietStart', '08:00')
            ->set('quietEnd', '08:00')
            ->call('save')
            ->assertHasErrors(['quietEnd']);
    }

    public function test_the_settings_page_is_listed_in_the_profile_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notification-preferences.edit'))->assertOk()->assertSee(route('notification-preferences.edit'));
    }

    public function test_the_test_notification_goes_through_every_active_channel(): void
    {
        $user = User::factory()->create(['email' => 'emma@example.test']);
        app(NotificationPreferences::class)->set($user, 'shift_assigned', 'mail', false);

        app(SendTestNotification::class)->handle($user);

        $this->assertSame(1, $user->notifications()->where('type', CoreNotificationTypes::TEST)->count());
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(['database', 'mail'], KintaiNotification::make(CoreNotificationTypes::TEST, 'x')->via($user));
    }

    public function test_the_test_notification_page_action_sends_it(): void
    {
        $user = User::factory()->create(['email' => 'emma@example.test']);

        Livewire::actingAs($user)->test('pages::settings.notifications')->call('sendTest');

        $this->assertSame(1, $user->notifications()->count());
    }
}
