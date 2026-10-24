<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Repositories\DatabaseNotificationPreferenceRepository;
use kintai\Domain\Eloquent\NotificationPreference;
use PHPUnit\Framework\TestCase;

/** Contre le vrai schéma de `notification_preferences` (2026_10_21_000000), sans mock. */
final class DatabaseNotificationPreferenceRepositoryTest extends TestCase
{
    private DatabaseNotificationPreferenceRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('notification_preferences', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->unique(['user_id', 'type', 'channel']);
        });

        $this->repo = new DatabaseNotificationPreferenceRepository();
    }

    public function testSetUpsertsOnUserTypeAndChannel(): void
    {
        $this->repo->set(1, 'shift_updated', 'push', false);
        $this->repo->set(1, 'shift_updated', 'push', true);
        $this->repo->set(1, 'shift_updated', 'email', true);

        $this->assertSame(2, NotificationPreference::count());
        $this->assertEquals(['shift_updated' => ['push' => true, 'email' => true]], $this->repo->findByUser(1));
    }

    public function testFindByUsersReturnsEachUsersDeviationsInOneCall(): void
    {
        $this->repo->set(1, 'a', 'push', false);
        $this->repo->set(2, 'a', 'email', true);
        $this->repo->set(3, 'a', 'push', false);

        $found = $this->repo->findByUsers([1, 2, 4]);

        $this->assertSame([1 => ['a' => ['push' => false]], 2 => ['a' => ['email' => true]]], $found);
        $this->assertSame([], $this->repo->findByUsers([]));
        $this->assertSame([], $this->repo->findByUser(99));
    }

    public function testResetRemovesOnlyThatChannel(): void
    {
        $this->repo->set(1, 'a', 'push', false);
        $this->repo->set(1, 'a', 'email', true);

        $this->repo->reset(1, 'a', 'push');

        $this->assertSame(['a' => ['email' => true]], $this->repo->findByUser(1));
    }

    public function testDeleteAllForUserKeepsOtherUsers(): void
    {
        $this->repo->set(1, 'a', 'push', false);
        $this->repo->set(1, 'b', 'push', false);
        $this->repo->set(2, 'a', 'push', false);

        $this->repo->deleteAllForUser(1);

        $this->assertSame([], $this->repo->findByUser(1));
        $this->assertSame(['a' => ['push' => false]], $this->repo->findByUser(2));
    }
}
