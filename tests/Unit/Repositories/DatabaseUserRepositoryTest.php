<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseUserRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\User as EloquentUser;

final class DatabaseUserRepositoryTest extends TestCase
{
    private DatabaseUserRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('employee_code')->nullable();
            $table->integer('is_active')->default(1);
            $table->string('password_hash')->default('');
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->string('display_name')->default('');
            $table->string('language')->default('fr');
            $table->timestamp('deleted_at')->nullable();
        });

        $this->repo = new DatabaseUserRepository();
    }

    private function user(int $id, string $email = 'user@test.com', ?string $empCode = null): array
    {
        return [
            'id' => $id, 
            'email' => $email, 
            'employee_code' => $empCode, 
            'is_active' => 1,
            'password_hash' => 'hash',
            'first_name' => 'First',
            'last_name' => 'Last',
            'display_name' => 'Display',
        ];
    }

    // -------------------------------------------------------------------------
    // findById()
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsUser(): void
    {
        $userData = $this->user(1);
        unset($userData['id']); // Let DB handle it
        $user = EloquentUser::create($userData);

        $found = $this->repo->findById($user->id);
        $this->assertNotNull($found);
        $this->assertEquals($user->email, $found['email']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    // -------------------------------------------------------------------------
    // findByEmail()
    // -------------------------------------------------------------------------

    public function testFindByEmailReturnsUser(): void
    {
        $userData = $this->user(1, 'alice@test.com');
        unset($userData['id']);
        EloquentUser::create($userData);

        $found = $this->repo->findByEmail('alice@test.com');
        $this->assertNotNull($found);
        $this->assertEquals('alice@test.com', $found['email']);
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findByEmail('nobody@test.com'));
    }

    // -------------------------------------------------------------------------
    // findByEmployeeCode()
    // -------------------------------------------------------------------------

    public function testFindByEmployeeCodeReturnsUser(): void
    {
        $userData = $this->user(1, 'bob@test.com', 'EMP001');
        unset($userData['id']);
        EloquentUser::create($userData);

        $found = $this->repo->findByEmployeeCode('EMP001');
        $this->assertNotNull($found);
        $this->assertEquals('EMP001', $found['employee_code']);
    }

    public function testFindByEmployeeCodeReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findByEmployeeCode('UNKNOWN'));
    }

    // -------------------------------------------------------------------------
    // findAll()
    // -------------------------------------------------------------------------

    public function testFindAllReturnsAllUsers(): void
    {
        EloquentUser::create(['email' => 'u1@test.com', 'password_hash' => 'h', 'first_name' => 'F', 'last_name' => 'L', 'display_name' => 'D']);
        EloquentUser::create(['email' => 'u2@test.com', 'password_hash' => 'h', 'first_name' => 'F', 'last_name' => 'L', 'display_name' => 'D']);

        $users = $this->repo->findAll();
        $this->assertCount(2, $users);
    }

    public function testFindAllReturnsEmptyWhenNoUsers(): void
    {
        $this->assertSame([], $this->repo->findAll());
    }

    // -------------------------------------------------------------------------
    // countActive()
    // -------------------------------------------------------------------------

    public function testCountActiveCountsOnlyActiveUsers(): void
    {
        EloquentUser::create(['email' => 'u1@test.com', 'is_active' => 1, 'password_hash' => 'h', 'first_name' => 'F', 'last_name' => 'L', 'display_name' => 'D']);
        EloquentUser::create(['email' => 'u2@test.com', 'is_active' => 0, 'password_hash' => 'h', 'first_name' => 'F', 'last_name' => 'L', 'display_name' => 'D']);
        EloquentUser::create(['email' => 'u3@test.com', 'is_active' => 1, 'password_hash' => 'h', 'first_name' => 'F', 'last_name' => 'L', 'display_name' => 'D', 'deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(1, $this->repo->countActive());
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveCreatesUser(): void
    {
        $data = [
            'email' => 'new@test.com', 
            'is_active' => 1,
            'password_hash' => 'hash',
            'first_name' => 'First',
            'last_name' => 'Last',
            'display_name' => 'Display',
        ];

        $result = $this->repo->save($data);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('new@test.com', $result['email']);
        
        $this->assertCount(1, EloquentUser::all());
    }

    public function testSaveUpdatesUser(): void
    {
        $user = EloquentUser::create([
            'email' => 'old@test.com', 
            'password_hash' => 'h', 
            'first_name' => 'F', 
            'last_name' => 'L', 
            'display_name' => 'D'
        ]);
        
        $data = ['id' => $user->id, 'email' => 'updated@test.com'];
        $result = $this->repo->save($data);
        
        $this->assertEquals('updated@test.com', $result['email']);
        $this->assertEquals('updated@test.com', EloquentUser::find($user->id)->email);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteDeletesUser(): void
    {
        $user = EloquentUser::create([
            'email' => 'del@test.com', 
            'password_hash' => 'h', 
            'first_name' => 'F', 
            'last_name' => 'L', 
            'display_name' => 'D'
        ]);
        
        $count = $this->repo->delete($user->id);
        $this->assertEquals(1, $count);
        $this->assertNull(EloquentUser::find($user->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete(999));
    }
}
