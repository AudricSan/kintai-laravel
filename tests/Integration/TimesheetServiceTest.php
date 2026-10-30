<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\BundleManager;
use Illuminate\Container\Container;
use App\Extension\Attendances;
use App\Extension\ExtensionRegistry;
use App\Models\Shift;
use App\Models\TimesheetApproval;
use App\Services\TimesheetService;
use Tests\Support\FakeAttendanceProvider;
use Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

/**
 * Feuilles de temps sur le vrai schéma : rapprochement des shifts et des pointages, qualification des écarts
 * (retard, départ anticipé, absence, hors planning, sortie oubliée), totaux, et validation de la semaine.
 */
final class TimesheetServiceTest extends TestCase
{
    private Capsule $capsule;
    private int $store;
    private int $alice;
    private int $bob;
    /** Lundi 2 novembre 2026, 23 h : toute la semaine du 2 au 8 qui précède est passée. */
    private \DateTimeImmutable $sunday;

    protected function setUp(): void
    {
        $this->capsule = kintai_test_database();

        kintai_test_migrate();

        $now = date('Y-m-d H:i:s');
        $this->capsule->table('stores')->insert(['name' => 'Shibuya', 'code' => 'SHB', 'timezone' => 'Asia/Tokyo', 'created_at' => $now, 'updated_at' => $now]);
        $this->store = (int) $this->capsule->table('stores')->where('code', 'SHB')->value('id');
        foreach (['alice', 'bob'] as $name) {
            $this->capsule->table('users')->insert([
                'first_name' => ucfirst($name), 'last_name' => 'Test', 'display_name' => ucfirst($name),
                'email' => $name . '@kintai.test', 'password_hash' => 'x', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->alice  = (int) $this->capsule->table('users')->where('email', 'alice@kintai.test')->value('id');
        $this->bob    = (int) $this->capsule->table('users')->where('email', 'bob@kintai.test')->value('id');
        $this->sunday = new \DateTimeImmutable('2026-11-08 23:00:00');
    }

    protected function tearDown(): void
    {
        $manager = FakeBundleManagerFactory::withActiveSlugs([]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    private function shift(int $userId, string $date, string $start = '09:00', string $end = '17:00', int $pause = 60): array
    {
        $cross = $end <= $start;
        $endDate = $cross ? date('Y-m-d', strtotime($date . ' +1 day')) : $date;
        return Shift::put([
            'store_id' => $this->store, 'user_id' => $userId, 'shift_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'cross_midnight' => $cross ? 1 : 0, 'pause_minutes' => $pause,
            'starts_at' => $date . ' ' . $start . ':00', 'ends_at' => $endDate . ' ' . $end . ':00',
        ]);
    }

    /** @param list<array<string, mixed>> $rows pointages (id, user_id, shift_date, clock_in_time, clock_out_time) */
    private function service(array $rows): TimesheetService
    {
        $rows = array_map(fn(array $r): array => $r + ['store_id' => $this->store], $rows);
        $manager = FakeBundleManagerFactory::withActiveSlugs(['timeclock'], attendance: ['timeclock' => [new FakeAttendanceProvider($rows)]]);
        return new TimesheetService(new Attendances(new ExtensionRegistry($manager)));
    }

    /** @return array<string, mixed> la seule ligne de la semaine pour cet employé et ce jour */
    private function rowOf(array $week, int $userId, string $date): array
    {
        $rows = array_values(array_filter($week['rows'], fn(array $r): bool => $r['user_id'] === $userId && $r['date'] === $date));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    public function testAShiftClockedOnTimeIsOkAndCountsItsNetMinutes(): void
    {
        $this->shift($this->alice, '2026-11-02');
        $week = $this->service([
            ['id' => 1, 'user_id' => $this->alice, 'shift_date' => '2026-11-02', 'clock_in_time' => '2026-11-02 09:03:00', 'clock_out_time' => '2026-11-02 17:02:00'],
        ])->week($this->store, '2026-11-02', $this->sunday);

        $row = $this->rowOf($week, $this->alice, '2026-11-02');
        $this->assertSame([TimesheetService::OK], $row['flags']);
        $this->assertSame(420, $row['planned_minutes']);
        $this->assertSame(419, $row['worked_minutes']);
        $this->assertSame(['planned_minutes' => 420, 'worked_minutes' => 419, 'anomalies' => 0], $week['totals'][$this->alice]);
        $this->assertSame('2026-11-08', $week['to']);
    }

    public function testLateArrivalAndEarlyLeaveAreFlaggedBeyondTheGracePeriod(): void
    {
        $this->shift($this->alice, '2026-11-03');
        $week = $this->service([
            ['id' => 1, 'user_id' => $this->alice, 'shift_date' => '2026-11-03', 'clock_in_time' => '2026-11-03 09:20:00', 'clock_out_time' => '2026-11-03 16:30:00'],
        ])->week($this->store, '2026-11-02', $this->sunday);

        $row = $this->rowOf($week, $this->alice, '2026-11-03');
        $this->assertSame([TimesheetService::LATE, TimesheetService::EARLY_LEAVE], $row['flags']);
        $this->assertSame(20, $row['late_minutes']);
        $this->assertSame(30, $row['early_minutes']);
        $this->assertSame(1, $week['totals'][$this->alice]['anomalies']);
    }

    public function testAPastShiftWithoutClockInIsANoShowAndAFutureOneIsUpcoming(): void
    {
        $this->shift($this->bob, '2026-11-04');
        $this->shift($this->bob, '2026-11-09');
        $service = $this->service([]);

        $this->assertSame([TimesheetService::NO_SHOW], $this->rowOf($service->week($this->store, '2026-11-02', $this->sunday), $this->bob, '2026-11-04')['flags']);
        $this->assertSame([TimesheetService::UPCOMING], $this->rowOf($service->week($this->store, '2026-11-09', $this->sunday), $this->bob, '2026-11-09')['flags']);
    }

    public function testAClockInWithoutShiftIsUnplannedAndAMissingClockOutIsFlagged(): void
    {
        $this->shift($this->alice, '2026-11-05');
        $week = $this->service([
            ['id' => 1, 'user_id' => $this->bob, 'shift_date' => '2026-11-05', 'clock_in_time' => '2026-11-05 10:00:00', 'clock_out_time' => '2026-11-05 14:00:00'],
            ['id' => 2, 'user_id' => $this->alice, 'shift_date' => '2026-11-05', 'clock_in_time' => '2026-11-05 09:00:00', 'clock_out_time' => null],
        ])->week($this->store, '2026-11-02', $this->sunday);

        $unplanned = $this->rowOf($week, $this->bob, '2026-11-05');
        $this->assertSame([TimesheetService::UNPLANNED], $unplanned['flags']);
        $this->assertSame(240, $unplanned['worked_minutes']);
        $this->assertSame(0, $unplanned['planned_minutes']);
        $this->assertSame([TimesheetService::MISSING_CLOCK_OUT], $this->rowOf($week, $this->alice, '2026-11-05')['flags']);
        $this->assertSame(1, $week['counts'][TimesheetService::UNPLANNED]);
    }

    public function testAnOpenClockInDuringTheShiftIsInProgress(): void
    {
        $this->shift($this->alice, '2026-11-06');
        $week = $this->service([
            ['id' => 1, 'user_id' => $this->alice, 'shift_date' => '2026-11-06', 'clock_in_time' => '2026-11-06 09:00:00', 'clock_out_time' => null],
        ])->week($this->store, '2026-11-02', new \DateTimeImmutable('2026-11-06 12:00:00'));

        $this->assertSame([TimesheetService::IN_PROGRESS], $this->rowOf($week, $this->alice, '2026-11-06')['flags']);
    }

    public function testTwoShiftsTheSameDayAreEachMatchedToTheNearestClockIn(): void
    {
        $this->shift($this->alice, '2026-11-07', '08:00', '12:00', 0);
        $this->shift($this->alice, '2026-11-07', '18:00', '22:00', 0);
        $week = $this->service([
            ['id' => 2, 'user_id' => $this->alice, 'shift_date' => '2026-11-07', 'clock_in_time' => '2026-11-07 18:15:00', 'clock_out_time' => '2026-11-07 22:00:00'],
            ['id' => 1, 'user_id' => $this->alice, 'shift_date' => '2026-11-07', 'clock_in_time' => '2026-11-07 08:00:00', 'clock_out_time' => '2026-11-07 12:00:00'],
        ])->week($this->store, '2026-11-02', $this->sunday);

        $rows = array_values(array_filter($week['rows'], fn(array $r): bool => $r['date'] === '2026-11-07'));
        $this->assertSame([1, 2], array_map(fn(array $r): int => (int) $r['attendance']['id'], $rows));
        $this->assertSame([[TimesheetService::OK], [TimesheetService::LATE]], array_column($rows, 'flags'));
    }

    public function testANightShiftEndsTheNextDay(): void
    {
        $this->shift($this->bob, '2026-11-02', '22:00', '06:00', 30);
        $week = $this->service([
            ['id' => 1, 'user_id' => $this->bob, 'shift_date' => '2026-11-02', 'clock_in_time' => '2026-11-02 22:00:00', 'clock_out_time' => '2026-11-03 06:40:00'],
        ])->week($this->store, '2026-11-02', $this->sunday);

        $row = $this->rowOf($week, $this->bob, '2026-11-02');
        $this->assertSame(450, $row['planned_minutes']);
        $this->assertSame([TimesheetService::OVERTIME], $row['flags']);
        $this->assertSame(40, $row['overtime_minutes']);
    }

    public function testAnApprovalKeepsTheFingerprintThatRevealsLaterChanges(): void
    {
        $shift = $this->shift($this->alice, '2026-11-02');
        $service = $this->service([]);
        $week = $service->week($this->store, '2026-11-02', $this->sunday);
        $key = ['store_id' => $this->store, 'week_start' => '2026-11-02'];

        $saved = TimesheetApproval::updateOrCreate($key, ['approved_by' => $this->alice, 'approved_at' => date('Y-m-d H:i:s'), 'fingerprint' => $week['fingerprint'], 'totals' => [$this->alice => ['planned_minutes' => 420, 'worked_minutes' => 0]]]);
        $this->assertSame($saved->id, TimesheetApproval::updateOrCreate($key, ['approved_by' => $this->bob, 'approved_at' => date('Y-m-d H:i:s'), 'fingerprint' => $week['fingerprint'], 'totals' => []])->id, 'une seule validation par semaine');
        $found = TimesheetApproval::where($key)->first();
        $this->assertSame($this->bob, $found['approved_by']);
        $this->assertSame($week['fingerprint'], $found['fingerprint']);

        Shift::put(['id' => $shift['id'], 'end_time' => '18:00', 'ends_at' => '2026-11-02 18:00:00']);
        $this->assertNotSame($found['fingerprint'], $service->week($this->store, '2026-11-02', $this->sunday)['fingerprint']);

        $this->assertSame(1, TimesheetApproval::where($key)->delete());
        $this->assertNull(TimesheetApproval::where($key)->first());
    }
}
