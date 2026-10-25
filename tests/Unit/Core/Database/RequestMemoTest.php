<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\RequestMemo;
use kintai\Core\Repositories\DatabaseRoleAssignmentRepository;
use kintai\Core\Repositories\DatabaseRoleRepository;
use kintai\Domain\Eloquent\Role;
use PHPUnit\Framework\TestCase;

/**
 * Lectures de droits gardées le temps d'une requête : une seule requête SQL par lecture répétée, et jamais de valeur
 * périmée après une écriture, même faite par une requête en masse.
 */
final class RequestMemoTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        RequestMemo::flush();
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        $schema->create('roles', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('slug');
            $t->boolean('is_system')->default(false);
            $t->string('color')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        $schema->create('role_permissions', function ($t) {
            $t->increments('id');
            $t->integer('role_id');
            $t->string('permission_key');
            $t->string('scope')->default('local');
        });
        $schema->create('role_assignments', function ($t) {
            $t->increments('id');
            $t->integer('user_id');
            $t->integer('role_id');
            $t->string('scope_type');
            $t->integer('scope_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        RequestMemo::flush();
    }

    public function testRepeatedReadsHitTheDatabaseOnce(): void
    {
        $roles = new DatabaseRoleRepository();
        $role  = $roles->save(['name' => 'Gérant', 'slug' => 'manager']);
        $roles->savePermissions((int) $role['id'], ['shifts.view', 'shifts.update']);
        (new DatabaseRoleAssignmentRepository())->assign(7, (int) $role['id'], 'store', 1);
        $db = $this->capsule->getConnection();
        $db->enableQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $roles->findById((int) $role['id']);
            $roles->getPermissions((int) $role['id']);
            (new DatabaseRoleAssignmentRepository())->findByUser(7);
        }

        $this->assertCount(3, $db->getQueryLog());
    }

    public function testAWriteThroughAModelIsSeenByTheNextRead(): void
    {
        $roles = new DatabaseRoleRepository();
        $role  = $roles->save(['name' => 'Gérant', 'slug' => 'manager']);
        $this->assertSame('Gérant', $roles->findById((int) $role['id'])['name']);

        Role::find($role['id'])->update(['name' => 'Responsable']);

        $this->assertSame('Responsable', $roles->findById((int) $role['id'])['name']);
    }

    public function testRevokedAssignmentsAndRemovedPermissionsAreSeenAtOnce(): void
    {
        $roles       = new DatabaseRoleRepository();
        $assignments = new DatabaseRoleAssignmentRepository();
        $role        = $roles->save(['name' => 'Gérant', 'slug' => 'manager']);
        $roles->savePermissions((int) $role['id'], ['shifts.view']);
        $assignment = $assignments->assign(7, (int) $role['id'], 'store', 1);
        $this->assertCount(1, $assignments->findByUser(7));
        $this->assertSame(['shifts.view'], $roles->getPermissions((int) $role['id']));

        $assignments->revoke((int) $assignment['id']);
        // Suppression en masse, sans modèle : la mémoire est quand même vidée.
        $roles->savePermissions((int) $role['id'], []);

        $this->assertSame([], $assignments->findByUser(7));
        $this->assertSame([], $roles->getPermissions((int) $role['id']));
    }

    public function testDeletingARoleForgetsItsAssignments(): void
    {
        $roles       = new DatabaseRoleRepository();
        $assignments = new DatabaseRoleAssignmentRepository();
        $role        = $roles->save(['name' => 'Gérant', 'slug' => 'manager']);
        $assignments->assign(7, (int) $role['id'], 'store', 1);
        $this->assertCount(1, $assignments->findByUser(7));

        $roles->delete((int) $role['id']);

        $this->assertSame([], $assignments->findByUser(7));
        $this->assertNull($roles->findById((int) $role['id']));
    }
}
