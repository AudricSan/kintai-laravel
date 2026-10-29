<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\BundleManager;
use Illuminate\Container\Container;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Extension\Events;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Repositories\DatabaseAvailabilityRepository;
use kintai\Core\Repositories\DatabaseShiftRepository;
use kintai\Core\Repositories\DatabaseShiftTypeRepository;
use kintai\Core\Repositories\DatabaseShiftWeekTemplateRepository;
use kintai\Core\Repositories\DatabaseStoreUserRepository;
use kintai\Core\Repositories\DatabaseUserRepository;
use kintai\Core\Services\AvailabilityGuard;
use kintai\Core\Services\ShiftChangeNotifier;
use kintai\Core\Services\WeekPlanner;
use kintai\Tests\Support\FakeAbsenceProvider;
use kintai\Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

/**
 * Copie de semaine et modèles de semaine sur le vrai schéma : recopie des horaires, et entrées écartées (employé parti,
 * congé, indisponibilité, chevauchement) avec leur raison.
 */
final class WeekPlannerTest extends TestCase
{
    private Capsule $capsule;
    private DatabaseShiftRepository $shifts;
    private WeekPlanner $planner;
    private int $store;
    private int $alice;
    private int $bob;
    private string $monday;
    private string $nextMonday;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner, 'capsule'))->setValue($runner, $this->capsule);
        (new \ReflectionProperty($runner, 'migrationsPath'))->setValue($runner, BASE_PATH . '/database/migrations/php');
        $runner->run();

        ShiftChangeNotifier::override(new ShiftChangeNotifier(new Events(), static function (): void {}));
        FakeAbsenceProvider::register([]);

        $now = date('Y-m-d H:i:s');
        $this->capsule->table('stores')->insert(['name' => 'Shibuya', 'code' => 'SHB', 'timezone' => 'Asia/Tokyo', 'created_at' => $now, 'updated_at' => $now]);
        $this->store = (int) $this->capsule->table('stores')->where('code', 'SHB')->value('id');
        foreach (['alice', 'bob'] as $name) {
            $this->capsule->table('users')->insert([
                'first_name' => ucfirst($name), 'last_name' => 'Test', 'display_name' => ucfirst($name),
                'email' => $name . '@kintai.test', 'password_hash' => 'x', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $uid = (int) $this->capsule->table('users')->where('email', $name . '@kintai.test')->value('id');
            $this->capsule->table('store_user')->insert(['store_id' => $this->store, 'user_id' => $uid, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->alice = (int) $this->capsule->table('users')->where('email', 'alice@kintai.test')->value('id');
        $this->bob   = (int) $this->capsule->table('users')->where('email', 'bob@kintai.test')->value('id');

        $this->monday     = '2026-11-02';
        $this->nextMonday = '2026-11-09';
        $this->shifts  = new DatabaseShiftRepository();
        $this->planner = new WeekPlanner(
            $this->shifts,
            new DatabaseShiftTypeRepository(),
            new DatabaseStoreUserRepository(),
            new DatabaseUserRepository(),
            new AvailabilityGuard(new DatabaseAvailabilityRepository()),
        );
    }

    protected function tearDown(): void
    {
        ShiftChangeNotifier::override(null);
        $manager = FakeBundleManagerFactory::withActiveSlugs([]);
        Container::getInstance()->instance(BundleManager::class, $manager);
        Container::getInstance()->instance(ExtensionRegistry::class, new ExtensionRegistry($manager));
    }

    private function shift(int $userId, string $date, string $start = '09:00', string $end = '17:00', array $extra = []): array
    {
        $cross = $end <= $start;
        $endDate = $cross ? date('Y-m-d', strtotime($date . ' +1 day')) : $date;
        return $this->shifts->save($extra + [
            'store_id' => $this->store, 'user_id' => $userId, 'shift_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'cross_midnight' => $cross ? 1 : 0, 'pause_minutes' => 30, 'notes' => 'Ouverture',
            'starts_at' => $date . ' ' . $start . ':00', 'ends_at' => $endDate . ' ' . $end . ':00',
        ]);
    }

    /** @return list<array{string, int, string, string}> date, employé, début, fin des shifts de la semaine */
    private function week(string $monday): array
    {
        $rows = array_map(
            fn(array $s): array => [(string) $s['shift_date'], (int) $s['user_id'], substr((string) $s['start_time'], 0, 5), substr((string) $s['end_time'], 0, 5)],
            $this->shifts->findByStoreBetween($this->store, $monday, date('Y-m-d', strtotime($monday . ' +6 days'))),
        );
        sort($rows);
        return $rows;
    }

    public function testCopyingAWeekRecreatesItsShiftsSevenDaysLater(): void
    {
        $this->shift($this->alice, '2026-11-02');
        $this->shift($this->bob, '2026-11-04', '22:00', '06:00');

        $result = $this->planner->apply($this->store, $this->nextMonday, $this->planner->entriesOfWeek($this->store, $this->monday), $this->alice);

        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([
            ['2026-11-09', $this->alice, '09:00', '17:00'],
            ['2026-11-11', $this->bob, '22:00', '06:00'],
        ], $this->week($this->nextMonday));
        $night = array_values(array_filter($result['created'], fn(array $s): bool => (int) $s['user_id'] === $this->bob))[0];
        $this->assertSame(1, (int) $night['cross_midnight']);
        $this->assertSame('2026-11-12 06:00:00', $night['ends_at']);
        $this->assertSame(480, (int) $night['duration_minutes']);
        $this->assertSame(30, (int) $night['pause_minutes']);
        $this->assertSame('Ouverture', $night['notes']);
        $this->assertSame($this->alice, (int) $night['created_by']);
    }

    public function testEntriesOutsideTheWeekAreIgnored(): void
    {
        $this->shift($this->alice, '2026-11-01');
        $this->shift($this->alice, '2026-11-09');

        $this->assertSame([], $this->planner->entriesOfWeek($this->store, $this->monday));
    }

    public function testAnEmployeeWhoLeftTheStoreIsSkipped(): void
    {
        $this->shift($this->bob, '2026-11-03');
        $this->capsule->table('users')->where('id', $this->bob)->update(['is_active' => 0]);

        $result = $this->planner->apply($this->store, $this->nextMonday, $this->planner->entriesOfWeek($this->store, $this->monday));

        $this->assertSame([], $result['created']);
        $this->assertSame(WeekPlanner::SKIP_NOT_MEMBER, $result['skipped'][0]['reason']);
        $this->assertSame('2026-11-10', $result['skipped'][0]['date']);
    }

    public function testApprovedLeaveIsRespected(): void
    {
        $this->shift($this->alice, '2026-11-02');
        $this->shift($this->alice, '2026-11-03');
        FakeAbsenceProvider::register([
            ['user_id' => $this->alice, 'store_id' => $this->store, 'status' => 'approved', 'start_date' => '2026-11-10', 'end_date' => '2026-11-12'],
        ]);

        $result = $this->planner->apply($this->store, $this->nextMonday, $this->planner->entriesOfWeek($this->store, $this->monday));

        $this->assertSame([['2026-11-09', $this->alice, '09:00', '17:00']], $this->week($this->nextMonday));
        $this->assertSame([WeekPlanner::SKIP_TIMEOFF], array_column($result['skipped'], 'reason'));
    }

    public function testADeclaredUnavailableDayIsSkipped(): void
    {
        $this->shift($this->alice, '2026-11-04'); // mercredi
        $now = date('Y-m-d H:i:s');
        $this->capsule->table('availabilities')->insert([
            'user_id' => $this->alice, 'store_id' => $this->store, 'day_of_week' => 3, 'start_time' => '00:00', 'end_time' => '23:59', 'is_available' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $result = $this->planner->apply($this->store, $this->nextMonday, $this->planner->entriesOfWeek($this->store, $this->monday));

        $this->assertSame([WeekPlanner::SKIP_UNAVAILABLE], array_column($result['skipped'], 'reason'));
    }

    public function testCopyingTwiceDoesNotDuplicate(): void
    {
        $this->shift($this->alice, '2026-11-02');
        $entries = $this->planner->entriesOfWeek($this->store, $this->monday);
        $this->planner->apply($this->store, $this->nextMonday, $entries);

        $second = $this->planner->apply($this->store, $this->nextMonday, $entries);

        $this->assertSame([], $second['created']);
        $this->assertSame([WeekPlanner::SKIP_OVERLAP], array_column($second['skipped'], 'reason'));
        $this->assertCount(1, $this->week($this->nextMonday));
    }

    public function testANonOverlappingShiftTheSameDayIsKept(): void
    {
        $this->shift($this->alice, '2026-11-02', '09:00', '13:00');
        $this->shift($this->alice, '2026-11-09', '14:00', '18:00');

        $result = $this->planner->apply($this->store, $this->nextMonday, $this->planner->entriesOfWeek($this->store, $this->monday));

        $this->assertCount(1, $result['created']);
        $this->assertCount(2, $this->week($this->nextMonday));
    }

    public function testATemplateIsStoredAndReappliedOnAnyWeek(): void
    {
        $this->shift($this->alice, '2026-11-02');
        $this->shift($this->bob, '2026-11-08', '12:00', '20:00');
        $templates = new DatabaseShiftWeekTemplateRepository();
        $saved = $templates->save($this->store, 'Semaine été', $this->planner->entriesOfWeek($this->store, $this->monday), $this->alice);

        // Même nom : le modèle est remplacé, pas dupliqué.
        $templates->save($this->store, 'Semaine été', $saved['entries'], $this->alice);
        $this->assertCount(1, $templates->findByStore($this->store));

        $template = $templates->findById($saved['id']);
        $this->assertSame([0, 6], array_column($template['entries'], 'day'));
        $result = $this->planner->apply($this->store, '2026-11-23', $template['entries']);

        $this->assertCount(2, $result['created']);
        $this->assertSame([
            ['2026-11-23', $this->alice, '09:00', '17:00'],
            ['2026-11-29', $this->bob, '12:00', '20:00'],
        ], $this->week('2026-11-23'));

        $this->assertSame(1, $templates->delete($saved['id']));
        $this->assertNull($templates->findById($saved['id']));
    }

    public function testMalformedEntriesAreIgnored(): void
    {
        $result = $this->planner->apply($this->store, $this->nextMonday, [
            ['day' => 9, 'user_id' => $this->alice, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['day' => 1, 'user_id' => $this->alice, 'start_time' => '25:00', 'end_time' => '17:00'],
        ]);

        $this->assertSame(['created' => [], 'skipped' => []], $result);
    }
}
