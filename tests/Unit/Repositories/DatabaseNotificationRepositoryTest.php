<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Repositories\DatabaseNotificationRepository;
use kintai\Domain\Eloquent\Notification;
use PHPUnit\Framework\TestCase;

/**
 * Insère contre le vrai schéma de la table `notifications` (celui de 2026_06_09_000012), sans mock : les tests de
 * contrôleurs mockent NotificationService/NotificationRepositoryInterface en entier et ne détecteraient pas une
 * colonne inexistante.
 */
final class DatabaseNotificationRepositoryTest extends TestCase
{
    private DatabaseNotificationRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('notifications', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type');
            $table->string('body_key')->nullable();
            $table->text('params')->nullable();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->repo = new DatabaseNotificationRepository();
    }

    private function add(int $userId, string $body = 'x', array $extra = []): array
    {
        return $this->repo->save(array_merge([
            'user_id' => $userId, 'type' => 't', 'body' => $body, 'is_read' => 0, 'created_at' => '2026-08-05 10:00:00',
        ], $extra));
    }

    public function testSaveInsertsAgainstRealSchemaWithBodyAndReferenceId(): void
    {
        $saved = $this->repo->save([
            'user_id'      => 5,
            'type'         => 'timeoff_approved',
            'reference_id' => 42,
            'body'         => 'Votre demande de congé a été approuvée.',
            'is_read'      => 0,
            'created_at'   => '2026-08-05 10:00:00',
        ]);

        $this->assertSame(5, (int) $saved['user_id']);
        $this->assertSame('timeoff_approved', $saved['type']);
        $this->assertSame(42, $saved['reference_id']);
        $this->assertSame('Votre demande de congé a été approuvée.', $saved['body']);
        $this->assertSame(0, $saved['is_read']);
        $this->assertSame(1, Notification::count());
    }

    public function testSaveWithoutReferenceIdOrLinkStoresNull(): void
    {
        $saved = $this->add(5);

        $this->assertNull($saved['reference_id']);
        $this->assertNull($saved['link']);
    }

    /** Le texte est retraduit à l'affichage : clé et paramètres doivent survivre à l'aller-retour en base. */
    public function testBodyKeyAndParamsRoundTrip(): void
    {
        $saved = $this->add(5, 'Nouveau shift.', [
            'body_key' => 'notif_shift_assigned_body',
            'params'   => ['date' => '2026-08-05', 'store' => 'Shibuya 渋谷'],
        ]);

        $found = $this->repo->findById((int) $saved['id']);
        $this->assertSame('notif_shift_assigned_body', $found['body_key']);
        $this->assertSame(['date' => '2026-08-05', 'store' => 'Shibuya 渋谷'], $found['params']);
    }

    public function testEmptyParamsAreStoredAsNullAndReadBackAsEmptyArray(): void
    {
        $saved = $this->add(5, 'x', ['params' => []]);

        $this->assertNull(Notification::find((int) $saved['id'])->params);
        $this->assertSame([], $this->repo->findById((int) $saved['id'])['params']);
    }

    public function testSaveAndFindByUserRoundTripLink(): void
    {
        $this->add(5, 'Nouveau shift.', ['link' => '/employee/shifts/day?start=2026-08-05']);

        $this->assertSame('/employee/shifts/day?start=2026-08-05', $this->repo->findByUser(5)[0]['link']);
    }

    public function testFindByUserReturnsNewestFirstWithOffset(): void
    {
        $a = $this->add(5, 'a');
        $b = $this->add(5, 'b');
        $c = $this->add(5, 'c');
        $this->add(6, 'other');

        $this->assertSame([(int) $c['id'], (int) $b['id']], array_map(fn($r) => (int) $r['id'], $this->repo->findByUser(5, 2)));
        $this->assertSame([(int) $a['id']], array_map(fn($r) => (int) $r['id'], $this->repo->findByUser(5, 2, 2)));
        $this->assertSame(3, $this->repo->countByUser(5));
    }

    /**
     * Cœur du correctif du polling : le curseur est l'id, pas created_at. Deux notifications créées dans la même
     * seconde (même created_at) sont toutes deux renvoyées, une seule fois chacune.
     */
    public function testFindAfterIdReturnsStrictlyNewerRowsInAscendingOrderRegardlessOfTimestamp(): void
    {
        $first  = $this->add(5, 'first');
        $second = $this->add(5, 'second');
        $third  = $this->add(5, 'third');
        $this->add(6, 'other user');

        $rows = $this->repo->findAfterId(5, (int) $first['id']);

        $this->assertSame(['second', 'third'], array_column($rows, 'body'));
        $this->assertSame([], $this->repo->findAfterId(5, (int) $third['id']));
        $this->assertCount(1, $this->repo->findAfterId(5, (int) $first['id'], 1));
        $this->assertSame((int) $second['id'], (int) $this->repo->findAfterId(5, (int) $first['id'], 1)[0]['id']);
    }

    public function testFindAfterIdIncludesReadRows(): void
    {
        $first = $this->add(5, 'first');
        $read  = $this->add(5, 'read');
        $this->repo->markRead((int) $read['id'], 5);

        $rows = $this->repo->findAfterId(5, (int) $first['id']);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['is_read']);
    }

    public function testMarkReadUpdatesIsReadDerivedField(): void
    {
        $saved = $this->add(5);

        $this->repo->markRead((int) $saved['id'], 5);

        $found = $this->repo->findById((int) $saved['id']);
        $this->assertSame(1, $found['is_read']);
        $this->assertNotNull($found['read_at']);
    }

    public function testMarkReadIgnoresOtherUsersNotification(): void
    {
        $saved = $this->add(5);

        $this->repo->markRead((int) $saved['id'], 999);

        $this->assertSame(0, $this->repo->findById((int) $saved['id'])['is_read']);
    }

    public function testCountUnreadOnlyCountsUnreadForThatUser(): void
    {
        $this->add(5, 'a');
        $this->add(5, 'b');
        $this->add(6, 'c');

        $this->assertSame(2, $this->repo->countUnread(5));

        $this->repo->markAllRead(5);
        $this->assertSame(0, $this->repo->countUnread(5));
        $this->assertSame(1, $this->repo->countUnread(6));
    }

    public function testDeleteRemovesRow(): void
    {
        $saved = $this->add(5);

        $this->assertSame(1, $this->repo->delete((int) $saved['id']));
        $this->assertNull($this->repo->findById((int) $saved['id']));
    }

    public function testDeleteAllForUserRemovesOnlyThatUsersRowsReadOrNot(): void
    {
        $keptOtherUser = $this->add(6, 'c');
        $this->add(5, 'a');
        $read = $this->add(5, 'b');
        $this->repo->markRead((int) $read['id'], 5);

        $this->repo->deleteAllForUser(5);

        $this->assertSame([], $this->repo->findByUser(5));
        $this->assertNotNull($this->repo->findById((int) $keptOtherUser['id']));
    }
    /** « Lu » automatique : seules les non lues du bon utilisateur, du bon type d'objet et du bon id sont touchées. */
    public function testMarkReadByReferenceTouchesOnlyTheMatchingUnreadNotificationsOfTheUser(): void
    {
        $hit1    = $this->add(5, 'a', ['reference_type' => 'shift', 'reference_id' => 12]);
        $hit2    = $this->add(5, 'b', ['reference_type' => 'shift', 'reference_id' => 13]);
        $otherId = $this->add(5, 'c', ['reference_type' => 'shift', 'reference_id' => 99]);
        $otherTy = $this->add(5, 'd', ['reference_type' => 'timeoff', 'reference_id' => 12]);
        $otherUs = $this->add(6, 'e', ['reference_type' => 'shift', 'reference_id' => 12]);
        $noRef   = $this->add(5, 'f');

        $this->repo->markReadByReference(5, 'shift', [12, 13]);

        foreach ([$hit1, $hit2] as $n) {
            $this->assertSame(1, $this->repo->findById((int) $n['id'])['is_read']);
        }
        foreach ([$otherId, $otherTy, $otherUs, $noRef] as $n) {
            $this->assertSame(0, $this->repo->findById((int) $n['id'])['is_read'], $n['body']);
        }
    }

    public function testMarkReadByReferenceKeepsTheOriginalReadDate(): void
    {
        $n = $this->add(5, 'a', ['reference_type' => 'shift', 'reference_id' => 12, 'read_at' => '2026-08-01 09:00:00']);

        $this->repo->markReadByReference(5, 'shift', [12]);

        $this->assertSame('2026-08-01 09:00:00', $this->repo->findById((int) $n['id'])['read_at']);
    }

    public function testMarkReadByReferenceWithNoIdsDoesNothing(): void
    {
        $n = $this->add(5, 'a', ['reference_type' => 'shift', 'reference_id' => 12]);

        $this->repo->markReadByReference(5, 'shift', []);

        $this->assertSame(0, $this->repo->findById((int) $n['id'])['is_read']);
    }

    /** La cloche ne montre que les non lues, de l'utilisateur concerné, plus récentes d'abord, dans la limite demandée. */
    public function testFindUnreadByUserSkipsReadAndOtherUsers(): void
    {
        $read   = $this->add(5, 'lue');
        $first  = $this->add(5, 'a');
        $this->add(6, 'autre utilisateur');
        $second = $this->add(5, 'b');
        $third  = $this->add(5, 'c');
        $this->repo->markRead((int) $read['id'], 5);

        $ids = array_column($this->repo->findUnreadByUser(5, 10), 'id');
        $this->assertSame([(int) $third['id'], (int) $second['id'], (int) $first['id']], array_map('intval', $ids));

        $this->assertCount(2, $this->repo->findUnreadByUser(5, 2));

        // Une notification lue quitte la cloche mais reste dans l'historique complet.
        $this->assertCount(4, $this->repo->findByUser(5, 10));
    }
}
