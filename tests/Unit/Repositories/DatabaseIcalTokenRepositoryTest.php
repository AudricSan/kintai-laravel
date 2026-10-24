<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseIcalTokenRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\IcalToken as EloquentIcalToken;

final class DatabaseIcalTokenRepositoryTest extends TestCase
{
    private DatabaseIcalTokenRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('ical_tokens', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('store_id');
            $table->string('token', 64)->unique();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['user_id', 'store_id']);
        });

        $this->repo = new DatabaseIcalTokenRepository();
    }

    public function testFindOrCreateForUserAndStoreCreatesWhenAbsent(): void
    {
        $token = $this->repo->findOrCreateForUserAndStore(1, 2);

        $this->assertArrayHasKey('token', $token);
        $this->assertSame(1, $token['user_id']);
        $this->assertSame(2, $token['store_id']);
        $this->assertCount(1, EloquentIcalToken::all());
    }

    public function testFindOrCreateForUserAndStoreReturnsExistingWithoutDuplicating(): void
    {
        $first  = $this->repo->findOrCreateForUserAndStore(1, 2);
        $second = $this->repo->findOrCreateForUserAndStore(1, 2);

        $this->assertSame($first['token'], $second['token']);
        $this->assertCount(1, EloquentIcalToken::all());
    }

    /**
     * Régression : la contrainte unique (user_id, store_id) fait bien échouer un
     * insert direct en doublon (sanity-check du filet de sécurité que
     * findOrCreateForUserAndStore() attrape en cas de requêtes concurrentes).
     */
    public function testDuplicateInsertViolatesUniqueConstraint(): void
    {
        $this->repo->save(['user_id' => 1, 'store_id' => 2, 'token' => 'aaa', 'created_at' => date('Y-m-d H:i:s')]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->repo->save(['user_id' => 1, 'store_id' => 2, 'token' => 'bbb', 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testFindByUserAndStoreReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->findByUserAndStore(1, 2));
    }

    public function testDeleteByUserAndStoreRemovesToken(): void
    {
        $this->repo->findOrCreateForUserAndStore(1, 2);
        $count = $this->repo->deleteByUserAndStore(1, 2);

        $this->assertSame(1, $count);
        $this->assertNull($this->repo->findByUserAndStore(1, 2));
    }
}
