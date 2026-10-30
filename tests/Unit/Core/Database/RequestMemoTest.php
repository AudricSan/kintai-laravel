<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Database\RequestMemo;
use App\Repositories\DatabaseRoleAssignmentRepository;
use App\Models\Role;
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
        $role  = Role::put(['name' => 'Gérant', 'slug' => 'manager']);
        Role::savePermissions((int) $role['id'], ['shifts.view', 'shifts.update']);
        (new DatabaseRoleAssignmentRepository())->assign(7, (int) $role['id'], 'store', 1);
        $db = $this->capsule->getConnection();
        $db->enableQueryLog();

        for ($i = 0; $i < 10; $i++) {
            Role::findById((int) $role['id']);
            Role::getPermissions((int) $role['id']);
            (new DatabaseRoleAssignmentRepository())->findByUser(7);
        }

        $this->assertCount(3, $db->getQueryLog());
    }

    public function testAWriteThroughAModelIsSeenByTheNextRead(): void
    {
        $role  = Role::put(['name' => 'Gérant', 'slug' => 'manager']);
        $this->assertSame('Gérant', Role::findById((int) $role['id'])['name']);

        Role::find($role['id'])->update(['name' => 'Responsable']);

        $this->assertSame('Responsable', Role::findById((int) $role['id'])['name']);
    }

    public function testRevokedAssignmentsAndRemovedPermissionsAreSeenAtOnce(): void
    {
        $assignments = new DatabaseRoleAssignmentRepository();
        $role        = Role::put(['name' => 'Gérant', 'slug' => 'manager']);
        Role::savePermissions((int) $role['id'], ['shifts.view']);
        $assignment = $assignments->assign(7, (int) $role['id'], 'store', 1);
        $this->assertCount(1, $assignments->findByUser(7));
        $this->assertSame(['shifts.view'], Role::getPermissions((int) $role['id']));

        $assignments->revoke((int) $assignment['id']);
        // Suppression en masse, sans modèle : la mémoire est quand même vidée.
        Role::savePermissions((int) $role['id'], []);

        $this->assertSame([], $assignments->findByUser(7));
        $this->assertSame([], Role::getPermissions((int) $role['id']));
    }

    public function testDeletingARoleForgetsItsAssignments(): void
    {
        $assignments = new DatabaseRoleAssignmentRepository();
        $role        = Role::put(['name' => 'Gérant', 'slug' => 'manager']);
        $assignments->assign(7, (int) $role['id'], 'store', 1);
        $this->assertCount(1, $assignments->findByUser(7));

        Role::remove((int) $role['id']);

        $this->assertSame([], $assignments->findByUser(7));
        $this->assertNull(Role::findById((int) $role['id']));
    }
}
