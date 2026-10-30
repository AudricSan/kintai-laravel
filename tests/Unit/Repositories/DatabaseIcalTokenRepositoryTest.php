<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use App\Models\IcalToken;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\IcalToken as EloquentIcalToken;

final class DatabaseIcalTokenRepositoryTest extends TestCase
{

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
    }

    public function testFindOrCreateForUserAndStoreCreatesWhenAbsent(): void
    {
        $token = IcalToken::findOrCreateForUserAndStore(1, 2);

        $this->assertArrayHasKey('token', $token);
        $this->assertSame(1, $token['user_id']);
        $this->assertSame(2, $token['store_id']);
        $this->assertCount(1, EloquentIcalToken::all());
    }

    public function testFindOrCreateForUserAndStoreReturnsExistingWithoutDuplicating(): void
    {
        $first  = IcalToken::findOrCreateForUserAndStore(1, 2);
        $second = IcalToken::findOrCreateForUserAndStore(1, 2);

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
        IcalToken::put(['user_id' => 1, 'store_id' => 2, 'token' => 'aaa', 'created_at' => date('Y-m-d H:i:s')]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        IcalToken::put(['user_id' => 1, 'store_id' => 2, 'token' => 'bbb', 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testFindByUserAndStoreReturnsNullWhenAbsent(): void
    {
        $this->assertNull(IcalToken::findByUserAndStore(1, 2));
    }

    public function testDeleteByUserAndStoreRemovesToken(): void
    {
        IcalToken::findOrCreateForUserAndStore(1, 2);
        $count = IcalToken::deleteByUserAndStore(1, 2);

        $this->assertSame(1, $count);
        $this->assertNull(IcalToken::findByUserAndStore(1, 2));
    }
}
