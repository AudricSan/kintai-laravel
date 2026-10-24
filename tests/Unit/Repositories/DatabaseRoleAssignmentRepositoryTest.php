<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseRoleAssignmentRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\RoleAssignment as EloquentRoleAssignment;

final class DatabaseRoleAssignmentRepositoryTest extends TestCase
{
    private DatabaseRoleAssignmentRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('role_assignments', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->string('scope_type');
            $table->integer('scope_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->repo = new DatabaseRoleAssignmentRepository();
    }

    // -------------------------------------------------------------------------
    // findById() / findByUser() / findByScope()
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsAssignment(): void
    {
        $a = EloquentRoleAssignment::create(['user_id' => 1, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3]);
        $found = $this->repo->findById($a->id);
        $this->assertNotNull($found);
        $this->assertSame(3, $found['scope_id']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindByUserReturnsAllAssignments(): void
    {
        EloquentRoleAssignment::create(['user_id' => 5, 'role_id' => 1, 'scope_type' => 'store', 'scope_id' => 1]);
        EloquentRoleAssignment::create(['user_id' => 5, 'role_id' => 1, 'scope_type' => 'store', 'scope_id' => 2]);
        EloquentRoleAssignment::create(['user_id' => 6, 'role_id' => 1, 'scope_type' => 'store', 'scope_id' => 1]);

        $this->assertCount(2, $this->repo->findByUser(5));
    }

    public function testFindByScopeGlobalReturnsOnlyGlobalAssignments(): void
    {
        EloquentRoleAssignment::create(['user_id' => 1, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null]);
        EloquentRoleAssignment::create(['user_id' => 2, 'role_id' => 1, 'scope_type' => 'store', 'scope_id' => 1]);

        $result = $this->repo->findByScope('global', null);
        $this->assertCount(1, $result);
        $this->assertSame(1, (int) $result[0]['user_id']);
    }

    public function testFindByScopeStoreReturnsMembersOfThatStore(): void
    {
        EloquentRoleAssignment::create(['user_id' => 1, 'role_id' => 1, 'scope_type' => 'store', 'scope_id' => 4]);
        EloquentRoleAssignment::create(['user_id' => 2, 'role_id' => 1, 'scope_type' => 'store', 'scope_id' => 5]);

        $result = $this->repo->findByScope('store', 4);
        $this->assertCount(1, $result);
        $this->assertSame(1, (int) $result[0]['user_id']);
    }

    public function testFindByRoleReturnsAllAssignmentsForThatRole(): void
    {
        EloquentRoleAssignment::create(['user_id' => 1, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]);
        EloquentRoleAssignment::create(['user_id' => 2, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 2]);
        EloquentRoleAssignment::create(['user_id' => 3, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1]);

        $this->assertCount(2, $this->repo->findByRole(2));
    }

    // -------------------------------------------------------------------------
    // assign()
    // -------------------------------------------------------------------------

    public function testAssignCreatesAssignment(): void
    {
        $result = $this->repo->assign(1, 2, 'store', 3);
        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result['user_id']);
        $this->assertSame('store', $result['scope_type']);
    }

    public function testAssignIsIdempotentForSameTuple(): void
    {
        $first  = $this->repo->assign(1, 2, 'store', 3);
        $second = $this->repo->assign(1, 2, 'store', 3);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $this->repo->findByUser(1));
    }

    public function testAssignAllowsSameRoleOnDifferentStores(): void
    {
        $this->repo->assign(1, 2, 'store', 3);
        $this->repo->assign(1, 2, 'store', 4);

        $this->assertCount(2, $this->repo->findByUser(1));
    }

    public function testAssignAllowsGlobalScopeWithNullScopeId(): void
    {
        $result = $this->repo->assign(1, 2, 'global', null);
        $this->assertNotNull($result);
        $this->assertNull($result['scope_id']);
    }

    // -------------------------------------------------------------------------
    // revoke()
    // -------------------------------------------------------------------------

    public function testRevokeDeletesAssignment(): void
    {
        $a = EloquentRoleAssignment::create(['user_id' => 1, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3]);
        $count = $this->repo->revoke($a->id);
        $this->assertSame(1, $count);
        $this->assertNull(EloquentRoleAssignment::find($a->id));
    }

    public function testRevokeReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->revoke(999));
    }
}
