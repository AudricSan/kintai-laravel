<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseRoleRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\Role as EloquentRole;
use kintai\Domain\Eloquent\RolePermission;
use kintai\Domain\Eloquent\RoleAssignment;

final class DatabaseRoleRepositoryTest extends TestCase
{
    private DatabaseRoleRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug');
            $table->integer('is_system')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $schema->create('role_permissions', function ($table) {
            $table->integer('role_id');
            $table->string('permission_key');
            $table->string('scope')->default('local');
        });
        $schema->create('role_assignments', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->string('scope_type');
            $table->integer('scope_id')->nullable();
        });

        $this->repo = new DatabaseRoleRepository();
    }

    // -------------------------------------------------------------------------
    // findAll() / findById() / findBySlug()
    // -------------------------------------------------------------------------

    public function testFindAllReturnsEveryRole(): void
    {
        EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        EloquentRole::create(['name' => 'Employé', 'slug' => 'employee']);

        $this->assertCount(2, $this->repo->findAll());
    }

    public function testFindByIdReturnsRole(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        $found = $this->repo->findById($r->id);
        $this->assertNotNull($found);
        $this->assertSame('manager', $found['slug']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindBySlugReturnsRole(): void
    {
        EloquentRole::create(['name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $found = $this->repo->findBySlug('owner');
        $this->assertNotNull($found);
        $this->assertSame(1, (int) $found['is_system']);
    }

    public function testFindBySlugReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findBySlug('nope'));
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveCreatesRole(): void
    {
        $result = $this->repo->save(['name' => 'Auditor', 'slug' => 'auditor']);
        $this->assertArrayHasKey('id', $result);
        $this->assertSame('Auditor', $result['name']);
    }

    public function testSaveUpdatesRole(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        $result = $this->repo->save(['id' => $r->id, 'name' => 'Store Manager']);
        $this->assertSame('Store Manager', $result['name']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteRemovesRole(): void
    {
        $r = EloquentRole::create(['name' => 'Auditor', 'slug' => 'auditor']);
        $count = $this->repo->delete($r->id);
        $this->assertSame(1, $count);
        $this->assertNull(EloquentRole::find($r->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete(999));
    }

    public function testDeleteAlsoRemovesDependentPermissionsAndAssignments(): void
    {
        $r = EloquentRole::create(['name' => 'Auditor', 'slug' => 'auditor']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'documents.view']);
        RoleAssignment::create(['user_id' => 5, 'role_id' => $r->id, 'scope_type' => 'store', 'scope_id' => 1]);

        $this->repo->delete($r->id);

        $this->assertSame(0, RolePermission::where('role_id', $r->id)->count());
        $this->assertSame(0, RoleAssignment::where('role_id', $r->id)->count());
    }

    // -------------------------------------------------------------------------
    // getPermissions() / savePermissions()
    // -------------------------------------------------------------------------

    public function testGetPermissionsReturnsAssignedKeys(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'employees.view']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'employees.create']);

        $keys = $this->repo->getPermissions($r->id);
        $this->assertCount(2, $keys);
        $this->assertContains('employees.view', $keys);
    }

    public function testSavePermissionsReplacesExistingSet(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'employees.view']);

        $this->repo->savePermissions($r->id, ['shifts.view', 'shifts.create']);

        $keys = $this->repo->getPermissions($r->id);
        $this->assertCount(2, $keys);
        $this->assertNotContains('employees.view', $keys);
        $this->assertContains('shifts.create', $keys);
    }

    // -------------------------------------------------------------------------
    // getGlobalPermissionKeys() / savePermissions() avec portée globale
    // -------------------------------------------------------------------------

    public function testGetGlobalPermissionKeysReturnsOnlyGlobalScopedKeys(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'shifts.view', 'scope' => 'global']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'shifts.update', 'scope' => 'local']);

        $keys = $this->repo->getGlobalPermissionKeys($r->id);
        $this->assertSame(['shifts.view'], $keys);
    }

    public function testSavePermissionsMarksListedKeysAsGlobal(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);

        $this->repo->savePermissions($r->id, ['shifts.view', 'shifts.update'], ['shifts.view']);

        $this->assertSame(['shifts.view'], $this->repo->getGlobalPermissionKeys($r->id));
        $this->assertCount(2, $this->repo->getPermissions($r->id));
    }

    public function testSavePermissionsIgnoresGlobalKeyNotInPermissionKeys(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);

        $this->repo->savePermissions($r->id, ['shifts.view'], ['shifts.view', 'employees.view']);

        $this->assertSame(['shifts.view'], $this->repo->getGlobalPermissionKeys($r->id));
        $this->assertSame(['shifts.view'], $this->repo->getPermissions($r->id));
    }

    public function testSavePermissionsDefaultsToLocalScopeWithoutThirdArgument(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);

        $this->repo->savePermissions($r->id, ['shifts.view']);

        $this->assertSame([], $this->repo->getGlobalPermissionKeys($r->id));
    }
}
