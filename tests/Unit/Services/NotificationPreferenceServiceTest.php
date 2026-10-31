<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use kintai\Contract\Extension\NotificationType;
use App\Bundles\Bundle;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\NotificationTypeRegistry;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Models\Notification;
use App\Models\NotificationPreference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Préférences de notification contre un vrai schéma SQLite : écarts seuls enregistrés, grille par catégorie,
 * heures silencieuses (y compris passant minuit) et, de bout en bout, le filtrage du push par NotificationService.
 */
final class NotificationPreferenceServiceTest extends TestCase
{
    private NotificationTypeRegistry $registry;

    /** @var array<int, array<string, mixed>> */
    private array $usersById = [];
    private \DateTimeImmutable $now;
    private int $pushCounter = 0;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        foreach ([7, 8] as $userId) {
            kintai_test_user($userId);
        }
        \Illuminate\Support\Facades\DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'device_push_tokens')) {
                $this->pushCounter++;
            }
        });

        $bundle = new class extends Bundle {
            public function __construct() {}

            public function getName(): string
            {
                return 'acme';
            }

            public function register(): void {}

            public function notificationTypes(): array
            {
                return [
                    'acme_news'  => ['icon' => '📰', 'category' => 'messages', 'channels' => ['push' => true, 'email' => false]],
                    'acme_legacy' => '🔔',
                ];
            }
        };
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, ['Bacme' => $bundle]);
        $this->registry = new NotificationTypeRegistry(new ExtensionRegistry($manager));

        $this->usersById = [7 => ['id' => 7, 'language' => 'fr', 'quiet_hours_start' => null, 'quiet_hours_end' => null]];
        kintai_test_users(findById: fn(int $id) => $this->usersById[$id] ?? null);
        $this->now  = new \DateTimeImmutable('2026-10-21 12:00:00');
    }

    private function service(): NotificationPreferenceService
    {
        return new NotificationPreferenceService($this->registry, fn() => $this->now);
    }

    // --- valeurs par défaut et écarts -----------------------------------------------------------------------------

    public function testWithoutDeviationTheTypeDefaultsApply(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isEnabled(7, 'shift_assigned', 'push'));
        $this->assertTrue($service->isEnabled(7, 'shift_assigned', 'email'), 'décision du planning : e-mail par défaut');
        $this->assertFalse($service->isEnabled(7, 'shift_updated', 'email'));
        $this->assertTrue($service->isEnabled(7, 'acme_legacy', 'push'), 'ancien format : push par défaut');
        $this->assertFalse($service->isEnabled(7, 'acme_legacy', 'email'));
        $this->assertTrue($service->isEnabled(7, 'type_inconnu', 'push'), 'type inconnu (bundle désactivé) : push par défaut');
        $this->assertFalse($service->isEnabled(7, 'type_inconnu', 'email'));
        $this->assertSame(0, NotificationPreference::count());
    }

    public function testSaveGridStoresOnlyDeviationsFromDefaults(): void
    {
        $service = $this->service();

        // Cochés = exactement les valeurs par défaut de tous les types : aucune ligne.
        $service->saveGrid(7, ['shift_assigned', 'shift_updated', 'shift_deleted', 'schedule_published', 'calendar_sync_error', 'acme_news', 'acme_legacy'], ['shift_assigned', 'shift_deleted', 'schedule_published']);
        $this->assertSame(0, NotificationPreference::count());

        // Push de shift_updated coupé, e-mail de shift_updated activé : deux écarts.
        $service->saveGrid(7, ['shift_assigned', 'shift_deleted', 'schedule_published', 'calendar_sync_error', 'acme_news', 'acme_legacy'], ['shift_assigned', 'shift_deleted', 'shift_updated', 'schedule_published']);
        $rows = NotificationPreference::orderBy('channel')->get(['type', 'channel', 'enabled'])->toArray();
        $this->assertCount(2, $rows);
        $this->assertSame([['type' => 'shift_updated', 'channel' => 'email', 'enabled' => true], ['type' => 'shift_updated', 'channel' => 'push', 'enabled' => false]], $rows);

        $fresh = $this->service();
        $this->assertFalse($fresh->isEnabled(7, 'shift_updated', 'push'));
        $this->assertTrue($fresh->isEnabled(7, 'shift_updated', 'email'));

        // Retour aux valeurs par défaut : les écarts disparaissent.
        $service->saveGrid(7, ['shift_assigned', 'shift_updated', 'shift_deleted', 'schedule_published', 'calendar_sync_error', 'acme_news', 'acme_legacy'], ['shift_assigned', 'shift_deleted', 'schedule_published']);
        $this->assertSame(0, NotificationPreference::count());
    }

    /** Un type apparu depuis l'affichage du formulaire (bundle activé entre-temps) n'est pas compté « décoché ». */
    public function testSaveGridLeavesTypesTheFormDidNotShowUntouched(): void
    {
        $service = $this->service();
        NotificationPreference::put(7, 'acme_news', 'push', false);

        // Le formulaire n'affichait que les trois types de shift ; acme_news (absent des listes cochées) ne bouge pas.
        $service->saveGrid(7, ['shift_assigned', 'shift_deleted'], ['shift_assigned', 'shift_deleted'], ['shift_assigned', 'shift_updated', 'shift_deleted']);

        $this->assertSame(
            [['type' => 'acme_news', 'channel' => 'push', 'enabled' => false], ['type' => 'shift_updated', 'channel' => 'push', 'enabled' => false]],
            NotificationPreference::orderBy('type')->get(['type', 'channel', 'enabled'])->toArray(),
            'shift_updated (affiché, décoché) est enregistré ; acme_news (non affiché) garde son état',
        );
        $this->assertTrue($service->isEnabled(7, 'acme_legacy', 'push'), 'un type non affiché reste à sa valeur par défaut, pas coupé');
    }

    public function testSaveGridDoesNotTouchPreferencesOfInactiveTypes(): void
    {
        // Préférence enregistrée pour le type d'un bundle désormais désactivé : la grille ne le connaît plus.
        NotificationPreference::put(7, 'old_bundle_type', 'push', false);

        $this->service()->saveGrid(7, [], []);

        $this->assertTrue(NotificationPreference::where('type', 'old_bundle_type')->exists());
    }

    public function testPreferencesAreIsolatedPerUser(): void
    {
        NotificationPreference::put(7, 'shift_assigned', 'push', false);
        $service = $this->service();

        $this->assertFalse($service->isEnabled(7, 'shift_assigned', 'push'));
        $this->assertTrue($service->isEnabled(8, 'shift_assigned', 'push'));
    }

    public function testGridGroupsActiveTypesByCategoryIncludingBundleTypes(): void
    {
        NotificationPreference::put(7, 'acme_news', 'push', false);

        $grid = $this->service()->grid(7);

        $this->assertSame(['planning', 'messages', 'system'], array_keys($grid));
        $this->assertSame(['shift_assigned', 'shift_updated', 'shift_deleted', 'schedule_published', 'calendar_sync_error'], array_map(fn($r) => $r['type']->type, $grid['planning']));
        $this->assertSame(['push' => false, 'email' => false], ['push' => $grid['messages'][0]['push'], 'email' => $grid['messages'][0]['email']]);
        $this->assertSame('acme_legacy', $grid['system'][0]['type']->type);
    }

    public function testSaveChangesAppliesOnlyTheNamedBoxes(): void
    {
        $service = $this->service();

        $invalid = $service->saveChanges(7, ['shift_updated' => ['push' => false], 'acme_news' => ['email' => true]]);

        $this->assertSame([], $invalid);
        $fresh = $this->service();
        $this->assertFalse($fresh->isEnabled(7, 'shift_updated', 'push'));
        $this->assertTrue($fresh->isEnabled(7, 'shift_assigned', 'push'), 'case non citée : inchangée');
        $this->assertTrue($fresh->isEnabled(7, 'acme_news', 'email'));
    }

    public function testSaveChangesIsAllOrNothing(): void
    {
        $service = $this->service();

        $invalid = $service->saveChanges(7, [
            'shift_updated' => ['push' => false],
            'type_inconnu'  => ['push' => false],
            'shift_deleted' => ['sms' => true],
            'acme_news'     => ['push' => 'non'],
        ]);

        $this->assertSame(['type_inconnu', 'shift_deleted.sms', 'acme_news.push'], $invalid);
        $this->assertSame(0, NotificationPreference::count(), 'une entrée invalide n\'enregistre rien');
    }

    // --- heures silencieuses --------------------------------------------------------------------------------------

    public function testQuietHoursAreDisabledByDefaultWithSuggestedRange(): void
    {
        $this->assertSame(['enabled' => false, 'start' => '22:00', 'end' => '07:00'], $this->service()->quietHours(7));
        $this->assertFalse($this->service()->isQuietNow(7));
    }

    public function testSaveQuietHoursPersistsAndNormalizesTimes(): void
    {
        $service = $this->service();

        $this->assertTrue($service->saveQuietHours(7, true, '22:00', '07:00:00'));

        $this->assertSame('22:00', \App\Models\User::findById(7)['quiet_hours_start']);
        $this->assertSame('07:00', \App\Models\User::findById(7)['quiet_hours_end']);
        $this->assertSame(['enabled' => true, 'start' => '22:00', 'end' => '07:00'], $service->quietHours(7));
    }

    public function testSaveQuietHoursRejectsInvalidOrEmptyRangesAndKeepsPreviousValue(): void
    {
        $service = $this->service();
        $service->saveQuietHours(7, true, '21:00', '06:00');

        foreach ([['25:00', '06:00'], ['21:00', ''], ['abc', 'def'], ['08:00', '08:00'], [null, null]] as [$start, $end]) {
            $this->assertFalse($service->saveQuietHours(7, true, $start, $end), json_encode([$start, $end]));
        }
        $this->assertFalse($service->saveQuietHours(99, true, '21:00', '06:00'), 'utilisateur inconnu');

        $this->assertSame(['enabled' => true, 'start' => '21:00', 'end' => '06:00'], $service->quietHours(7));
    }

    public function testDisablingQuietHoursClearsThem(): void
    {
        $service = $this->service();
        $service->saveQuietHours(7, true, '21:00', '06:00');

        $this->assertTrue($service->saveQuietHours(7, false, '21:00', '06:00'));

        $this->assertNull(\App\Models\User::findById(7)['quiet_hours_start']);
        $this->assertFalse($service->quietHours(7)['enabled']);
        $this->assertFalse($service->isQuietNow(7));
    }

    /** @return array<string, array{string, string, string, bool}> */
    public static function ranges(): array
    {
        return [
            'journée, dedans'                 => ['12:00', '09:00', '17:00', true],
            'journée, début inclus'           => ['09:00', '09:00', '17:00', true],
            'journée, fin exclue'             => ['17:00', '09:00', '17:00', false],
            'journée, dehors'                 => ['20:00', '09:00', '17:00', false],
            'minuit, avant minuit'            => ['23:30', '22:00', '07:00', true],
            'minuit, après minuit'            => ['03:00', '22:00', '07:00', true],
            'minuit, début inclus'            => ['22:00', '22:00', '07:00', true],
            'minuit, fin exclue'              => ['07:00', '22:00', '07:00', false],
            'minuit, en journée'              => ['12:00', '22:00', '07:00', false],
            'plage vide'                      => ['12:00', '12:00', '12:00', false],
        ];
    }

    #[DataProvider('ranges')]
    public function testInRange(string $time, string $start, string $end, bool $expected): void
    {
        $this->assertSame($expected, NotificationPreferenceService::inRange($time, $start, $end));
    }

    public function testIsQuietNowUsesTheClockAndTheUsersRange(): void
    {
        $service = $this->service();
        $service->saveQuietHours(7, true, '22:00', '07:00');

        $this->now = new \DateTimeImmutable('2026-10-21 23:15:00');
        $this->assertTrue($service->isQuietNow(7));
        $this->now = new \DateTimeImmutable('2026-10-22 06:59:00');
        $this->assertTrue($service->isQuietNow(7));
        $this->now = new \DateTimeImmutable('2026-10-22 07:00:00');
        $this->assertFalse($service->isQuietNow(7));
    }

    // --- NotificationService : le push respecte préférences et heures silencieuses ------------------------------

    /** @return array{NotificationService, int} service et nombre de recherches d'appareils (= push tentés) */
    private function notifier(?NotificationPreferenceService $prefs, int &$pushAttempts): NotificationService
    {
        // Un push tenté = une recherche des appareils de l'utilisateur (comptée par l'écouteur de setUp).
        $this->pushCounter = &$pushAttempts;
        $push = new PushNotificationService(['fcm' => ['enabled' => true, 'project_id' => 'kintai-test', 'credentials_path' => '']]);

        $translations = kintai_test_real_translator();

        return new NotificationService($push, $translations, $this->registry, $prefs);
    }

    public function testNotifyAttemptsPushByDefaultAndWithoutPreferenceService(): void
    {
        $attempts = 0;
        $this->notifier($this->service(), $attempts)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');
        $this->assertSame(1, $attempts);

        $attempts = 0;
        $this->notifier(null, $attempts)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');
        $this->assertSame(1, $attempts, 'sans service de préférences : comportement inchangé');
    }

    public function testNotifySkipsPushWhenTheUserTurnedItOffButStillFillsTheBell(): void
    {
        NotificationPreference::put(7, 'shift_assigned', 'push', false);
        $attempts = 0;

        $this->notifier($this->service(), $attempts)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame(0, $attempts);
        $this->assertSame(1, Notification::where('user_id', 7)->count(), 'la cloche reçoit toujours la notification');
    }

    /** Heures silencieuses : le push part quand même (muet, voir pushPayload), la cloche aussi ; rien n'est retenu. */
    public function testNotifyStillSendsPushDuringQuietHours(): void
    {
        $service = $this->service();
        $service->saveQuietHours(7, true, '22:00', '07:00');
        $this->now = new \DateTimeImmutable('2026-10-21 23:00:00');
        $attempts = 0;

        $this->notifier($service, $attempts)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame(1, $attempts);
        $this->assertSame(1, Notification::where('user_id', 7)->count());

        // Hors plage : même comportement côté envoi.
        $this->now = new \DateTimeImmutable('2026-10-22 09:00:00');
        $this->notifier($service, $attempts)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');
        $this->assertSame(2, $attempts);
    }

    /** Un push que l'utilisateur a coupé pour ce type reste coupé, heures silencieuses ou pas. */
    public function testQuietHoursDoNotReenableAPushTheUserTurnedOff(): void
    {
        $service = $this->service();
        $service->saveChanges(7, ['shift_assigned' => ['push' => false]]);
        $service->saveQuietHours(7, true, '22:00', '07:00');
        $this->now = new \DateTimeImmutable('2026-10-21 23:00:00');
        $attempts = 0;

        $this->notifier($service, $attempts)->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame(0, $attempts);
    }

    public function testNotifyManyAppliesEachRecipientsOwnPreferences(): void
    {
        $this->usersById[8] = ['id' => 8, 'language' => 'fr', 'quiet_hours_start' => null, 'quiet_hours_end' => null];
        kintai_test_users(findById: fn(int $id) => $this->usersById[$id] ?? null);
        NotificationPreference::put(8, 'shift_assigned', 'push', false);
        $attempts = 0;

        $this->notifier($this->service(), $attempts)->notifyMany([7, 8], 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame(1, $attempts, 'seul l\'utilisateur 7 reçoit le push');
        $this->assertSame(2, Notification::count(), 'les deux ont la cloche');
    }
}
