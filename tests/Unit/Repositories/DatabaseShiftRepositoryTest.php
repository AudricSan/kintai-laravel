<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Extension\Events;
use kintai\Core\Repositories\DatabaseShiftRepository;
use kintai\Core\Services\ShiftChangeNotifier;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\Shift as EloquentShift;
use kintai\Domain\Eloquent\ShiftDeletionLog as EloquentShiftDeletionLog;

final class DatabaseShiftRepositoryTest extends TestCase
{
    private DatabaseShiftRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('shifts', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('shift_date');
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->integer('cross_midnight')->default(0);
            $table->integer('shift_type_id')->nullable();
            $table->integer('pause_minutes')->default(0);
            $table->integer('is_open')->default(0);
            $table->integer('ical_sequence')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $capsule->getConnection()->getSchemaBuilder()->create('shift_deletion_log', function ($table) {
            $table->increments('id');
            $table->integer('shift_id');
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('shift_date')->nullable();
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->integer('cross_midnight')->default(0);
            $table->integer('shift_type_id')->nullable();
            $table->integer('pause_minutes')->default(0);
            $table->integer('ical_sequence')->default(0);
            $table->timestamp('shift_created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        $this->repo = new DatabaseShiftRepository();
    }

    private function shift(int $id, int $storeId = 1, int $userId = 10, string $date = '2025-06-15'): array
    {
        return ['id' => $id, 'store_id' => $storeId, 'user_id' => $userId, 'shift_date' => $date];
    }

    // -------------------------------------------------------------------------
    // findByStoreBetween()
    // -------------------------------------------------------------------------

    public function testFindByStoreBetweenReturnsOnlyTheStoreAndTheRangeBoundsIncluded(): void
    {
        EloquentShift::insert([
            $this->shift(1, 1, 10, '2025-06-01'),
            $this->shift(2, 1, 10, '2025-06-10'),
            $this->shift(3, 1, 10, '2025-06-30'),
            $this->shift(4, 1, 10, '2025-07-01'),
            $this->shift(5, 2, 10, '2025-06-10'),
        ]);

        $ids = array_column($this->repo->findByStoreBetween(1, '2025-06-01', '2025-06-30'), 'id');

        $this->assertSame([1, 2, 3], array_map('intval', $ids));
    }

    // -------------------------------------------------------------------------
    // findBetween()
    // -------------------------------------------------------------------------

    public function testFindBetweenFiltersStoresAndRangeBoundsIncluded(): void
    {
        EloquentShift::insert([
            $this->shift(1, 1, 10, '2025-06-01'),
            $this->shift(2, 2, 10, '2025-06-15'),
            $this->shift(3, 3, 10, '2025-06-15'),
            $this->shift(4, 1, 10, '2025-06-30'),
            $this->shift(5, 1, 10, '2025-07-01'),
            $this->shift(6, 1, 10, '2025-05-31'),
        ]);

        $ids = fn(?array $stores) => array_map('intval', array_column($this->repo->findBetween($stores, '2025-06-01', '2025-06-30'), 'id'));

        $this->assertSame([1, 2, 4], $ids([1, 2]));
        $this->assertSame([1, 2, 3, 4], $ids(null));
        $this->assertSame([], $ids([]));
    }

    public function testFindBetweenOrdersByDateThenId(): void
    {
        EloquentShift::insert([
            $this->shift(1, 1, 10, '2025-06-20'),
            $this->shift(2, 1, 10, '2025-06-05'),
            $this->shift(3, 1, 11, '2025-06-05'),
        ]);

        $ids = array_map('intval', array_column($this->repo->findBetween([1], '2025-06-01', '2025-06-30'), 'id'));

        $this->assertSame([2, 3, 1], $ids);
    }

    public function testFindByStoreBetweenOrdersByUserThenDateThenId(): void
    {
        // Ordre explicite (et non celui de l'index que la base choisit) : les statistiques en dépendent.
        EloquentShift::insert([
            $this->shift(1, 1, 20, '2025-06-02'),
            $this->shift(2, 1, 10, '2025-06-05'),
            $this->shift(3, 1, 10, '2025-06-01'),
            $this->shift(4, 1, 20, '2025-06-01'),
        ]);

        $ids = array_column($this->repo->findByStoreBetween(1, '2025-06-01', '2025-06-30'), 'id');

        $this->assertSame([3, 2, 4, 1], array_map('intval', $ids));
    }

    public function testFindByStoreBetweenReturnsPlainArraysLikeFindByStore(): void
    {
        EloquentShift::insert([$this->shift(1, 1, 10, '2025-06-10')]);

        $this->assertSame($this->repo->findByStore(1), $this->repo->findByStoreBetween(1, '2025-06-01', '2025-06-30'));
    }

    // -------------------------------------------------------------------------
    // findById()
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsShift(): void
    {
        $s = EloquentShift::create(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15']);
        $found = $this->repo->findById($s->id);
        $this->assertNotNull($found);
        $this->assertEquals($s->id, $found['id']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    // -------------------------------------------------------------------------
    // findByStore()
    // -------------------------------------------------------------------------

    public function testFindByStoreReturnsResults(): void
    {
        EloquentShift::create(['store_id' => 2, 'user_id' => 1, 'shift_date' => '2025-06-15']);
        EloquentShift::create(['store_id' => 2, 'user_id' => 2, 'shift_date' => '2025-06-15']);
        EloquentShift::create(['store_id' => 1, 'user_id' => 1, 'shift_date' => '2025-06-15']);

        $this->assertCount(2, $this->repo->findByStore(2));
    }

    // -------------------------------------------------------------------------
    // findByUser()
    // -------------------------------------------------------------------------

    public function testFindByUserReturnsResults(): void
    {
        EloquentShift::create(['store_id' => 1, 'user_id' => 5, 'shift_date' => '2025-06-15']);
        EloquentShift::create(['store_id' => 2, 'user_id' => 5, 'shift_date' => '2025-06-16']);

        $this->assertCount(2, $this->repo->findByUser(5));
    }

    public function testFindByUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->repo->findByUser(5));
    }

    // -------------------------------------------------------------------------
    // findByDate()
    // -------------------------------------------------------------------------

    public function testFindByDateReturnsMatchingShifts(): void
    {
        EloquentShift::create(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15']);
        
        $result = $this->repo->findByDate(1, '2025-06-15');
        $this->assertCount(1, $result);
        $this->assertEquals('2025-06-15', $result[0]['shift_date']);
    }

    // -------------------------------------------------------------------------
    // findByUserAndDate()
    // -------------------------------------------------------------------------

    public function testFindByUserAndDateReturnsResults(): void
    {
        EloquentShift::create(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-07-01']);
        
        $result = $this->repo->findByUserAndDate(10, 1, '2025-07-01');
        $this->assertCount(1, $result);
    }

    public function testFindByUserAndDateReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->repo->findByUserAndDate(10, 1, '2025-07-01'));
    }

    // -------------------------------------------------------------------------
    // findAll()
    // -------------------------------------------------------------------------

    public function testFindAllReturnsAllShifts(): void
    {
        EloquentShift::create(['store_id' => 1, 'user_id' => 1, 'shift_date' => '2025-06-15']);
        EloquentShift::create(['store_id' => 2, 'user_id' => 2, 'shift_date' => '2025-06-15']);

        $this->assertCount(2, $this->repo->findAll());
    }

    // -------------------------------------------------------------------------
    // findAllByDate()
    // -------------------------------------------------------------------------

    public function testFindAllByDateReturnsShifts(): void
    {
        EloquentShift::create(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-20']);
        EloquentShift::create(['store_id' => 2, 'user_id' => 11, 'shift_date' => '2025-06-20']);

        $this->assertCount(2, $this->repo->findAllByDate('2025-06-20'));
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveCreatesShift(): void
    {
        $data = ['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15'];
        $result = $this->repo->save($data);
        
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals(0, $result['ical_sequence']);
        $this->assertCount(1, EloquentShift::all());
    }

    public function testSaveIncrementsIcalSequenceOnUpdate(): void
    {
        $s = EloquentShift::create([
            'store_id' => 1, 
            'user_id' => 10, 
            'shift_date' => '2025-06-15', 
            'ical_sequence' => 2
        ]);
        
        $data = ['id' => $s->id, 'shift_date' => '2025-06-16'];
        $result = $this->repo->save($data);
        
        $this->assertEquals(3, $result['ical_sequence']);
        $this->assertNotNull($result['updated_at']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteDeletesShift(): void
    {
        $s = EloquentShift::create(['store_id' => 1, 'user_id' => 1, 'shift_date' => '2025-06-15']);
        $count = $this->repo->delete($s->id);
        
        $this->assertEquals(1, $count);
        $this->assertNull(EloquentShift::find($s->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete(999));
    }

    /**
     * Régression : delete() faisait un hard delete sans laisser aucune trace,
     * donc le flux iCal ne pouvait jamais émettre de VEVENT CANCELLED pour un
     * shift supprimé après export (voir IcalController::feed()). delete() doit
     * maintenant journaliser le shift dans shift_deletion_log avant de le
     * supprimer réellement.
     */
    public function testDeleteRecordsDeletionLogBeforeHardDeleting(): void
    {
        $s = EloquentShift::create([
            'store_id'       => 1,
            'user_id'        => 7,
            'shift_date'     => '2025-06-15',
            'start_time'     => '09:00',
            'end_time'       => '17:00',
            'cross_midnight' => 0,
            'shift_type_id'  => 3,
            'pause_minutes'  => 30,
            'ical_sequence'  => 2,
        ]);

        $this->repo->delete($s->id);

        $log = EloquentShiftDeletionLog::first();
        $this->assertNotNull($log);
        $this->assertSame($s->id, $log->shift_id);
        $this->assertSame(1, $log->store_id);
        $this->assertSame(7, $log->user_id);
        $this->assertSame('2025-06-15', $log->shift_date);
        $this->assertSame('09:00', $log->start_time);
        $this->assertSame('17:00', $log->end_time);
        $this->assertSame(3, $log->shift_type_id);
        $this->assertSame(30, $log->pause_minutes);
        // ical_sequence bumped comme pour toute autre modification du shift
        // (cf. save()/closeOpenShiftTo()), pour que le CANCELLED soit traité
        // comme une révision plus récente que le dernier CONFIRMED.
        $this->assertSame(3, $log->ical_sequence);
        $this->assertNotNull($log->deleted_at);
    }

    public function testDeleteDoesNotRecordLogWhenShiftNotFound(): void
    {
        $this->repo->delete(999);

        $this->assertSame(0, EloquentShiftDeletionLog::count());
    }

    // -------------------------------------------------------------------------
    // findRecentDeletionsByUserAndStore()
    // -------------------------------------------------------------------------

    public function testFindRecentDeletionsByUserAndStoreReturnsShiftShapedArray(): void
    {
        $s = EloquentShift::create([
            'store_id'   => 1,
            'user_id'    => 7,
            'shift_date' => '2025-06-15',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);
        $this->repo->delete($s->id);

        $result = $this->repo->findRecentDeletionsByUserAndStore(7, 1, date('Y-m-d H:i:s', strtotime('-30 days')));

        $this->assertCount(1, $result);
        $this->assertSame($s->id, $result[0]['id']);
        $this->assertSame('2025-06-15', $result[0]['shift_date']);
        $this->assertNotEmpty($result[0]['deleted_at']);
    }

    public function testFindRecentDeletionsByUserAndStoreExcludesOtherUserOrStore(): void
    {
        $s = EloquentShift::create(['store_id' => 1, 'user_id' => 7, 'shift_date' => '2025-06-15']);
        $this->repo->delete($s->id);

        $this->assertSame([], $this->repo->findRecentDeletionsByUserAndStore(999, 1, date('Y-m-d H:i:s', strtotime('-30 days'))));
        $this->assertSame([], $this->repo->findRecentDeletionsByUserAndStore(7, 999, date('Y-m-d H:i:s', strtotime('-30 days'))));
    }

    public function testFindRecentDeletionsByUserAndStoreExcludesEntriesOlderThanSince(): void
    {
        $s = EloquentShift::create(['store_id' => 1, 'user_id' => 7, 'shift_date' => '2025-06-15']);
        $this->repo->delete($s->id);

        // "since" dans le futur par rapport à la suppression qu'on vient de faire
        $future = date('Y-m-d H:i:s', strtotime('+1 minute'));
        $this->assertSame([], $this->repo->findRecentDeletionsByUserAndStore(7, 1, $future));
    }

    // -------------------------------------------------------------------------
    // closeOpenShiftTo()
    // -------------------------------------------------------------------------

    public function testCloseOpenShiftToAssignsUserAndClosesShift(): void
    {
        $s = EloquentShift::create(['store_id' => 1, 'user_id' => null, 'shift_date' => '2025-06-15', 'is_open' => 1, 'ical_sequence' => 2]);

        $result = $this->repo->closeOpenShiftTo($s->id, 42);

        $this->assertNotNull($result);
        $this->assertSame(42, $result['user_id']);
        $this->assertSame(0, $result['is_open']);
        $this->assertSame(3, $result['ical_sequence']);
    }

    /**
     * Régression : deux admins approuvent deux candidatures différentes du même
     * shift ouvert. Le premier appel de closeOpenShiftTo() ferme le shift ; le
     * second (WHERE is_open = 1 dans l'UPDATE) ne doit trouver aucune ligne à
     * modifier et retourner null, au lieu d'écraser silencieusement l'affectation
     * du premier.
     */
    public function testCloseOpenShiftToReturnsNullWhenAlreadyClosedByConcurrentApproval(): void
    {
        $s = EloquentShift::create(['store_id' => 1, 'user_id' => null, 'shift_date' => '2025-06-15', 'is_open' => 1]);

        $first  = $this->repo->closeOpenShiftTo($s->id, 42);
        $second = $this->repo->closeOpenShiftTo($s->id, 99);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(42, EloquentShift::find($s->id)->user_id);
    }

    public function testCloseOpenShiftToReturnsNullWhenShiftNotFound(): void
    {
        $this->assertNull($this->repo->closeOpenShiftTo(999, 42));
    }

    // -------------------------------------------------------------------------
    // Annonce des écritures (événements bundles + synchronisation des agendas)
    // -------------------------------------------------------------------------

    /** @return list<array{string, array, ?array}> */
    private function recordNotifications(): \ArrayObject
    {
        $seen = new \ArrayObject();
        ShiftChangeNotifier::override(new ShiftChangeNotifier(new Events(), function (string $kind, array $shift, ?array $before) use ($seen) {
            $seen->append([$kind, $shift, $before]);
        }));
        return $seen;
    }

    protected function tearDown(): void
    {
        ShiftChangeNotifier::override(null);
    }

    public function testSaveAnnouncesACreationWithoutPreviousState(): void
    {
        $seen = $this->recordNotifications();

        $saved = $this->repo->save(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15']);

        $this->assertCount(1, $seen);
        [$kind, $shift, $before] = $seen[0];
        $this->assertSame('saved', $kind);
        $this->assertSame($saved['id'], $shift['id']);
        $this->assertNull($before);
    }

    public function testSaveAnnouncesAnUpdateWithThePreviousEmployee(): void
    {
        $created = EloquentShift::create(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15']);
        $seen = $this->recordNotifications();

        $this->repo->save(['id' => $created->id, 'user_id' => 11]);

        [$kind, $shift, $before] = $seen[0];
        $this->assertSame('saved', $kind);
        $this->assertSame(11, (int) $shift['user_id']);
        $this->assertSame(10, (int) $before['user_id']);
    }

    public function testDeleteAnnouncesTheRemovedShiftOnlyWhenOneWasDeleted(): void
    {
        $created = EloquentShift::create(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15']);
        $seen = $this->recordNotifications();

        $this->repo->delete(999);
        $this->assertCount(0, $seen, 'rien à annoncer quand le shift n’existe pas');

        $this->repo->delete($created->id);
        $this->assertCount(1, $seen);
        $this->assertSame('deleted', $seen[0][0]);
        $this->assertSame($created->id, $seen[0][1]['id']);
    }

    public function testClosingAnOpenShiftIsAnnouncedAsASaveForTheNewEmployee(): void
    {
        $open = EloquentShift::create(['store_id' => 1, 'user_id' => null, 'shift_date' => '2025-06-15', 'is_open' => 1]);
        $seen = $this->recordNotifications();

        $this->repo->closeOpenShiftTo($open->id, 42);

        $this->assertCount(1, $seen);
        $this->assertSame('saved', $seen[0][0]);
        $this->assertSame(42, (int) $seen[0][1]['user_id']);
        $this->assertNull($seen[0][2]['user_id']);
    }

    public function testAFailingNotifierNeverUndoesTheWrite(): void
    {
        ShiftChangeNotifier::override(new ShiftChangeNotifier(new Events(), function () {
            throw new \RuntimeException('agenda indisponible');
        }));

        $saved = $this->repo->save(['store_id' => 1, 'user_id' => 10, 'shift_date' => '2025-06-15']);

        $this->assertNotNull(EloquentShift::find($saved['id']));
    }
}
