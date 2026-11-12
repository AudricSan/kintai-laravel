<?php

namespace Tests\Feature\System;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationInbox;
use App\Notifications\NotificationPreferences;
use App\Settings\AppSettings;
use App\System\CoreSystemAlerts;
use App\System\SystemAlerts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Kintai\Contracts\System\SystemAlert;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class SystemAlertsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->seedRoles();
        $this->owner = $this->owner();
        // Sauvegarde récente : par défaut aucune alerte.
        $this->settings()->set('backup.last_success_at', now()->toIso8601String());
    }

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    private function alerts(): SystemAlerts
    {
        return $this->app->make(SystemAlerts::class);
    }

    private function detectors(): CoreSystemAlerts
    {
        return $this->app->make(CoreSystemAlerts::class);
    }

    private function pushSubscription(): void
    {
        WebPushSubscription::query()->create([
            'user_id' => $this->owner->id,
            'endpoint' => 'https://push.example.test/abc',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/abc'),
            'p256dh' => 'p',
            'auth' => 'a',
        ]);
    }

    // Types

    public function test_the_system_types_are_registered_without_the_deferred_ones(): void
    {
        $registry = $this->app->make(NotificationTypeRegistry::class);

        foreach (['migration_pending', 'backup_failed', 'backup_stale', 'channel_broken'] as $type) {
            $this->assertSame('system', $registry->get($type)?->category);
            $this->assertContains($type, $this->alerts()->types());
        }

        foreach (['core_update_available', 'bundle_update_available', 'license_quota'] as $deferred) {
            $this->assertFalse($registry->has($deferred));
            $this->assertNotContains($deferred, $this->alerts()->types());
        }
    }

    // Détecteurs

    public function test_pending_migrations_are_detected(): void
    {
        $this->assertNull($this->detectors()->migrationPending());

        $name = (string) DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $name)->delete();

        $alert = $this->detectors()->migrationPending();
        $this->assertNotNull($alert);
        $this->assertSame(1, $alert->params['count']);

        // La clé change avec l'ensemble des migrations en attente.
        $other = (string) DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $other)->delete();
        $this->assertNotSame($alert->key, $this->detectors()->migrationPending()?->key);
    }

    public function test_a_failed_backup_is_detected_until_the_next_success(): void
    {
        $this->assertNull($this->detectors()->backupFailed());

        $this->settings()->set('backup.last_failure', '2026-11-05 03:00:00 - Disk full');

        $alert = $this->detectors()->backupFailed();
        $this->assertNotNull($alert);
        $this->assertSame('2026-11-05 03:00:00 - Disk full', $alert->params['message']);
        $this->assertSame('/admin/backups', $alert->link);

        $this->settings()->set('backup.last_failure', '');
        $this->assertNull($this->detectors()->backupFailed());
    }

    public function test_a_stale_backup_is_detected_only_when_automatic_backups_are_enabled(): void
    {
        $this->assertNull($this->detectors()->backupStale());

        $this->settings()->set('backup.last_success_at', now()->subDays(2)->addMinute()->toIso8601String());
        $this->assertNull($this->detectors()->backupStale());

        $this->settings()->set('backup.last_success_at', now()->subDays(3)->toIso8601String());
        $this->assertNotNull($this->detectors()->backupStale());

        $this->settings()->set('backup.last_success_at', '');
        $this->assertSame('none', $this->detectors()->backupStale()?->key);

        $this->settings()->set('backup.auto_enabled', false);
        $this->assertNull($this->detectors()->backupStale());
    }

    public function test_web_push_with_incomplete_keys_or_subscriptions_without_keys_is_broken(): void
    {
        $this->assertSame([], $this->detectors()->channelProblems());

        $this->settings()->set('webpush.public_key', 'public-only');
        $this->assertContains('web_push_keys', $this->detectors()->channelProblems());

        $this->settings()->set('webpush.private_key', 'private');
        $this->assertSame([], $this->detectors()->channelProblems());

        $this->settings()->setMany(['webpush.public_key' => '', 'webpush.private_key' => '']);
        $this->pushSubscription();
        $this->assertContains('web_push_keys', $this->detectors()->channelProblems());
    }

    public function test_fcm_enabled_without_a_readable_configuration_is_broken(): void
    {
        config(['kintai.fcm.enabled' => false]);
        $this->assertNotContains('fcm', $this->detectors()->channelProblems());

        config(['kintai.fcm.enabled' => true, 'kintai.fcm.project_id' => '', 'kintai.fcm.credentials_path' => '']);
        $this->assertContains('fcm', $this->detectors()->channelProblems());

        $file = tempnam(sys_get_temp_dir(), 'fcm');
        config(['kintai.fcm.project_id' => 'demo', 'kintai.fcm.credentials_path' => $file]);

        file_put_contents($file, 'not json');
        $this->assertContains('fcm', $this->detectors()->channelProblems());

        file_put_contents($file, json_encode(['client_email' => 'a@b.test', 'private_key' => 'key']));
        $this->assertNotContains('fcm', $this->detectors()->channelProblems());

        unlink($file);
    }

    public function test_configured_mail_without_a_public_url_is_broken(): void
    {
        config(['mail.default' => 'smtp', 'app.url' => 'http://localhost']);
        $this->assertContains('email_public_url', $this->detectors()->channelProblems());

        $this->settings()->set('organization.public_url', 'https://kintai.example.test');
        $this->assertNotContains('email_public_url', $this->detectors()->channelProblems());

        $this->settings()->forget('organization.public_url');
        config(['app.url' => 'https://kintai.example.test']);
        $this->assertNotContains('email_public_url', $this->detectors()->channelProblems());

        // Un transport qui n'envoie rien (journal) n'a pas besoin d'URL publique.
        config(['mail.default' => 'log', 'app.url' => 'http://localhost']);
        $this->assertNotContains('email_public_url', $this->detectors()->channelProblems());
    }

    public function test_the_channel_alert_key_follows_the_set_of_problems(): void
    {
        $this->assertNull($this->detectors()->channelBroken());

        $this->settings()->set('webpush.public_key', 'public-only');
        $first = $this->detectors()->channelBroken();
        $this->assertNotNull($first);
        $this->assertSame('/admin/settings', $first->link);

        config(['kintai.fcm.enabled' => true, 'kintai.fcm.project_id' => '']);
        $this->assertNotSame($first->key, $this->detectors()->channelBroken()?->key);
    }

    // Destinataires et préférences

    public function test_system_types_are_offered_in_the_preferences_of_owners_only(): void
    {
        $registry = $this->app->make(NotificationTypeRegistry::class);
        $employee = $this->employeeOf(Store::factory()->create());

        $this->assertArrayNotHasKey('system', $registry->grid());
        $this->assertArrayNotHasKey('system', $registry->grid($employee));
        $this->assertSame(['migration_pending', 'backup_failed', 'backup_stale', 'channel_broken'], array_map(fn (NotificationType $t): string => $t->key, $registry->grid($this->owner)['system']));

        $this->assertArrayHasKey('backup_failed', app(NotificationPreferences::class)->matrix($this->owner));
        $this->assertArrayNotHasKey('backup_failed', app(NotificationPreferences::class)->matrix($employee));
    }

    public function test_only_active_owners_are_notified(): void
    {
        Notification::fake();
        $inactive = $this->owner();
        $inactive->forceFill(['is_active' => false])->save();
        $manager = $this->managerOf(Store::factory()->create());
        $this->settings()->set('backup.last_failure', 'boom');

        $this->assertSame(['backup_failed'], $this->alerts()->run());

        Notification::assertSentTo($this->owner, KintaiNotification::class, fn (KintaiNotification $n): bool => $n->type === 'backup_failed' && $n->link === '/admin/backups');
        Notification::assertNotSentTo($inactive, KintaiNotification::class);
        Notification::assertNotSentTo($manager, KintaiNotification::class);
    }

    public function test_the_notification_reaches_the_bell_with_a_translatable_body(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');

        $this->alerts()->run();

        $notification = $this->owner->notifications()->firstOrFail();
        $this->assertSame('backup_failed', $notification->type);
        $this->assertSame('system', $notification->data['reference_type']);
        $this->assertSame(CoreSystemAlerts::BODIES['backup_failed'], $notification->data['body']);
    }

    public function test_preferences_are_respected(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');
        app(NotificationPreferences::class)->set($this->owner, 'backup_failed', NotificationType::CHANNEL_MAIL, false);

        $notification = KintaiNotification::make('backup_failed', 'x');

        $this->assertSame(['database'], $notification->via($this->owner));

        app(NotificationPreferences::class)->set($this->owner, 'backup_failed', NotificationType::CHANNEL_MAIL, true);
        $this->assertContains('mail', $notification->via($this->owner));
    }

    public function test_nothing_happens_without_an_active_owner(): void
    {
        Notification::fake();
        $this->owner->forceFill(['is_active' => false])->save();
        $this->settings()->set('backup.last_failure', 'boom');

        $this->assertSame([], $this->alerts()->run());

        // Le premier Owner actif recevra l'alerte en cours : l'état n'a pas été mémorisé.
        $this->owner->forceFill(['is_active' => true])->save();
        $this->assertSame(['backup_failed'], $this->alerts()->run());
    }

    // Déduplication

    public function test_an_alert_is_not_sent_again_while_its_state_is_unchanged(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');

        $this->assertSame(['backup_failed'], $this->alerts()->run());
        $this->assertSame([], $this->alerts()->run());
        $this->assertSame([], $this->alerts()->run());

        $this->assertCount(1, $this->owner->notifications);
    }

    public function test_a_changed_state_sends_a_new_alert(): void
    {
        $this->settings()->set('backup.last_failure', 'first failure');
        $this->alerts()->run();

        $this->settings()->set('backup.last_failure', 'second failure');
        $this->assertSame(['backup_failed'], $this->alerts()->run());

        $this->assertCount(2, $this->owner->notifications);
    }

    public function test_a_resolved_problem_clears_the_state_and_reads_the_notification_then_a_reappearance_alerts_again(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');
        $this->alerts()->run();
        $this->assertCount(1, $this->owner->unreadNotifications);

        $this->settings()->set('backup.last_failure', '');
        $this->assertSame([], $this->alerts()->run());
        $this->assertCount(0, $this->owner->fresh()->unreadNotifications);
        $this->assertStringNotContainsString('backup_failed', $this->settings()->string('system.alerts_state'));

        $this->settings()->set('backup.last_failure', 'boom');
        $this->assertSame(['backup_failed'], $this->alerts()->run());
        $this->assertCount(2, $this->owner->fresh()->notifications);
    }

    public function test_a_failing_detector_does_not_block_the_others_nor_clear_the_state(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');
        $this->alerts()->run();

        $custom = new SystemAlerts($this->settings(), app(Authorizer::class), app(NotificationInbox::class));
        $custom->register('backup_failed', 4, fn () => throw new \RuntimeException('detector down'));
        $custom->register('backup_stale', 5, fn (): SystemAlert => new SystemAlert('k', CoreSystemAlerts::BODIES['backup_stale'], ['days' => 2]));

        $this->assertSame(['backup_stale'], $custom->run());
        $this->assertStringContainsString('backup_failed', $this->settings()->string('system.alerts_state'));
    }

    public function test_a_type_cannot_be_registered_twice(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->alerts()->register('backup_failed', 4, fn (): ?SystemAlert => null);
    }

    // Commande et exécution au passage

    public function test_the_command_runs_the_alerts_and_is_scheduled_hourly(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');

        $this->artisan('kintai:system:alerts')->assertSuccessful();

        $this->assertCount(1, $this->owner->notifications);

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'kintai:system:alerts'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_run_if_due_runs_at_most_once_per_hour(): void
    {
        $this->settings()->set('backup.last_failure', 'boom');

        $this->assertSame(['backup_failed'], $this->alerts()->runIfDue());

        $this->settings()->set('backup.last_failure', 'another');
        $this->assertSame([], $this->alerts()->runIfDue());

        Carbon::setTestNow(now()->addMinutes(59));
        $this->assertSame([], $this->alerts()->runIfDue());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(['backup_failed'], $this->alerts()->runIfDue());
    }

    public function test_an_owner_visit_triggers_the_check_once_and_other_users_do_not(): void
    {
        config(['kintai.system_alerts_on_visit' => true]);
        $this->settings()->set('backup.last_failure', 'boom');
        $store = Store::factory()->create();
        $manager = $this->managerOf($store);

        $this->actingAs($manager)->get(route('dashboard'));
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(0, $this->settings()->systemAlertsLastRun());

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(now()->getTimestamp(), $this->settings()->systemAlertsLastRun());

        // Dans l'heure : plus de passe, même avec un nouvel état.
        $this->settings()->set('backup.last_failure', 'changed');
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_the_visit_check_can_be_switched_off(): void
    {
        config(['kintai.system_alerts_on_visit' => false]);
        $this->settings()->set('backup.last_failure', 'boom');

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $this->assertSame(0, $this->owner->notifications()->count());
    }
}
