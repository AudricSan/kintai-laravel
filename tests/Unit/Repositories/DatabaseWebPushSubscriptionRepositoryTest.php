<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Repositories\DatabaseWebPushSubscriptionRepository;
use kintai\Domain\Eloquent\WebPushSubscription;
use PHPUnit\Framework\TestCase;

/** Contre le vrai schéma de `web_push_subscriptions` (2026_10_22_000000), sans mock. */
final class DatabaseWebPushSubscriptionRepositoryTest extends TestCase
{
    private DatabaseWebPushSubscriptionRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('web_push_subscriptions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();
        });

        $this->repo = new DatabaseWebPushSubscriptionRepository();
    }

    public function testSaveCreatesThenUpdatesTheSameEndpoint(): void
    {
        $first  = $this->repo->save(1, 'https://push.example/a', 'key1', 'auth1', 'Firefox');
        $second = $this->repo->save(1, 'https://push.example/a', 'key2', 'auth2', 'Firefox 2');

        $this->assertSame(1, WebPushSubscription::count());
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('key2', $second['p256dh']);
        $this->assertSame('Firefox 2', $this->repo->findByEndpoint('https://push.example/a')['user_agent']);
    }

    public function testAnEndpointAlreadyKnownMovesToTheNewestAccount(): void
    {
        $this->repo->save(1, 'https://push.example/shared', 'k', 'a', null);
        $this->repo->save(2, 'https://push.example/shared', 'k', 'a', null);

        $this->assertSame([], $this->repo->findByUser(1));
        $this->assertCount(1, $this->repo->findByUser(2));
    }

    public function testFindByUsersGroupsInOneCall(): void
    {
        $this->repo->save(1, 'https://push.example/1a', 'k', 'a', null);
        $this->repo->save(1, 'https://push.example/1b', 'k', 'a', null);
        $this->repo->save(2, 'https://push.example/2a', 'k', 'a', null);
        $this->repo->save(3, 'https://push.example/3a', 'k', 'a', null);

        $found = $this->repo->findByUsers([1, 2, 4]);

        $this->assertSame([1, 2], array_keys($found));
        $this->assertCount(2, $found[1]);
        $this->assertSame([], $this->repo->findByUsers([]));
    }

    public function testDeleteByEndpointCanBeScopedToAUser(): void
    {
        $this->repo->save(1, 'https://push.example/a', 'k', 'a', null);

        $this->assertSame(0, $this->repo->deleteByEndpoint('https://push.example/a', 2), 'un autre compte ne peut pas le retirer');
        $this->assertNotNull($this->repo->findByEndpoint('https://push.example/a'));
        $this->assertSame(1, $this->repo->deleteByEndpoint('https://push.example/a', 1));
        $this->assertNull($this->repo->findByEndpoint('https://push.example/a'));
    }

    public function testVeryLongEndpointsAreSupported(): void
    {
        $endpoint = 'https://push.example/' . str_repeat('x', 1500);

        $this->repo->save(1, $endpoint, 'k', 'a', null);

        $this->assertSame($endpoint, $this->repo->findByEndpoint($endpoint)['endpoint']);
    }

    public function testDeleteByIdTouchAndDeleteAllForUser(): void
    {
        $a = $this->repo->save(1, 'https://push.example/a', 'k', 'a', null);
        $this->repo->save(1, 'https://push.example/b', 'k', 'a', null);
        $this->repo->save(2, 'https://push.example/c', 'k', 'a', null);

        $this->repo->deleteById((int) $a['id']);
        $this->assertCount(1, $this->repo->findByUser(1));

        $this->repo->deleteAllForUser(1);
        $this->assertSame([], $this->repo->findByUser(1));
        $this->assertCount(1, $this->repo->findByUser(2), 'les autres comptes sont intacts');
    }
}
