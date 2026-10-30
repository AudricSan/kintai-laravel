<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use App\Models\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Role as EloquentRole;
use App\Models\RolePermission;
use App\Models\RoleAssignment;

final class DatabaseRoleRepositoryTest extends TestCase
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
        \Illuminate\Database\Eloquent\Model::unsetEventDispatcher();

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
    }

    // -------------------------------------------------------------------------
    // findAll() / findById() / findBySlug()
    // -------------------------------------------------------------------------

    public function testFindAllReturnsEveryRole(): void
    {
        EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        EloquentRole::create(['name' => 'Employé', 'slug' => 'employee']);

        $this->assertCount(2, Role::findAll());
    }

    public function testFindByIdReturnsRole(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        $found = Role::findById($r->id);
        $this->assertNotNull($found);
        $this->assertSame('manager', $found['slug']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull(Role::findById(999));
    }

    public function testFindBySlugReturnsRole(): void
    {
        EloquentRole::create(['name' => 'Owner', 'slug' => 'owner', 'is_system' => 1]);
        $found = Role::findBySlug('owner');
        $this->assertNotNull($found);
        $this->assertSame(1, (int) $found['is_system']);
    }

    public function testFindBySlugReturnsNullWhenNotFound(): void
    {
        $this->assertNull(Role::findBySlug('nope'));
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveCreatesRole(): void
    {
        $result = Role::put(['name' => 'Auditor', 'slug' => 'auditor']);
        $this->assertArrayHasKey('id', $result);
        $this->assertSame('Auditor', $result['name']);
    }

    public function testSaveUpdatesRole(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        $result = Role::put(['id' => $r->id, 'name' => 'Store Manager']);
        $this->assertSame('Store Manager', $result['name']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteRemovesRole(): void
    {
        $r = EloquentRole::create(['name' => 'Auditor', 'slug' => 'auditor']);
        $count = Role::remove($r->id);
        $this->assertSame(1, $count);
        $this->assertNull(EloquentRole::find($r->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, Role::remove(999));
    }

    public function testDeleteAlsoRemovesDependentPermissionsAndAssignments(): void
    {
        $r = EloquentRole::create(['name' => 'Auditor', 'slug' => 'auditor']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'documents.view']);
        RoleAssignment::create(['user_id' => 5, 'role_id' => $r->id, 'scope_type' => 'store', 'scope_id' => 1]);

        Role::remove($r->id);

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

        $keys = Role::getPermissions($r->id);
        $this->assertCount(2, $keys);
        $this->assertContains('employees.view', $keys);
    }

    public function testSavePermissionsReplacesExistingSet(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);
        RolePermission::create(['role_id' => $r->id, 'permission_key' => 'employees.view']);

        Role::savePermissions($r->id, ['shifts.view', 'shifts.create']);

        $keys = Role::getPermissions($r->id);
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

        $keys = Role::getGlobalPermissionKeys($r->id);
        $this->assertSame(['shifts.view'], $keys);
    }

    public function testSavePermissionsMarksListedKeysAsGlobal(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);

        Role::savePermissions($r->id, ['shifts.view', 'shifts.update'], ['shifts.view']);

        $this->assertSame(['shifts.view'], Role::getGlobalPermissionKeys($r->id));
        $this->assertCount(2, Role::getPermissions($r->id));
    }

    public function testSavePermissionsIgnoresGlobalKeyNotInPermissionKeys(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);

        Role::savePermissions($r->id, ['shifts.view'], ['shifts.view', 'employees.view']);

        $this->assertSame(['shifts.view'], Role::getGlobalPermissionKeys($r->id));
        $this->assertSame(['shifts.view'], Role::getPermissions($r->id));
    }

    public function testSavePermissionsDefaultsToLocalScopeWithoutThirdArgument(): void
    {
        $r = EloquentRole::create(['name' => 'Manager', 'slug' => 'manager']);

        Role::savePermissions($r->id, ['shifts.view']);

        $this->assertSame([], Role::getGlobalPermissionKeys($r->id));
    }
}
