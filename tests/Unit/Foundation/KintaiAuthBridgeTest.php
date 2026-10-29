<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Foundation;

use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container as IlluminateContainer;
use kintai\Core\Auth\AuthService;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Container;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Foundation\Auth\KintaiGate;
use kintai\Foundation\Auth\KintaiGuard;
use kintai\Foundation\Auth\KintaiUser;
use PHPUnit\Framework\TestCase;

final class KintaiAuthBridgeTest extends TestCase
{
    private ?Container $previous = null;

    private const USER = ['id' => 7, 'email' => 'aiko@example.com', 'is_active' => 1, 'deleted_at' => null,
        'password_hash' => '', 'first_name' => 'Aiko'];

    protected function setUp(): void
    {
        $this->previous = (new \ReflectionProperty(Container::class, 'instance'))->getValue();

        $user = self::USER;
        $user['password_hash'] = password_hash('Secret-Passw0rd!', PASSWORD_BCRYPT, ['cost' => 4]);
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id) => $id === 7 ? $user : null);
        $users->method('findByEmail')->willReturnCallback(fn(string $email) => $email === 'aiko@example.com' ? $user : null);

        // Rôle « Manager » (non système) sur le magasin 3, qui accorde shifts.update.
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn([['role_id' => 2, 'scope_type' => 'store', 'scope_id' => 3]]);
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 2, 'is_system' => 0]);
        $roles->method('getPermissions')->willReturn(['shifts.update']);

        $container = new Container();
        $container->instance(UserRepositoryInterface::class, $users);
        $container->instance(AuthService::class, new AuthService(
            $users,
            $this->createStub(StoreUserRepositoryInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $roles,
            $assignments,
            $this->createStub(RememberTokenRepositoryInterface::class),
        ));
        $container->instance(PermissionService::class, new PermissionService($assignments, $roles));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        session()->forget(['auth_user_id', 'auth_pw_fp']);
        Container::setInstance($this->previous);
    }

    public function testAGuestHasNoUser(): void
    {
        $guard = new KintaiGuard();

        $this->assertTrue($guard->guest());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function testTheSessionUserIsTheLaravelUser(): void
    {
        session()->put('auth_user_id', 7);
        $guard = new KintaiGuard();

        $this->assertTrue($guard->check());
        $this->assertSame(7, $guard->id());
        $user = $guard->user();
        $this->assertInstanceOf(KintaiUser::class, $user);
        $this->assertSame('Aiko', $user->first_name);
        $this->assertSame('aiko@example.com', $user['email']);
    }

    public function testCredentialsAreValidatedWithoutLoggingIn(): void
    {
        $guard = new KintaiGuard();

        $this->assertTrue($guard->validate(['email' => 'aiko@example.com', 'password' => 'Secret-Passw0rd!']));
        $this->assertFalse($guard->validate(['email' => 'aiko@example.com', 'password' => 'wrong']));
        $this->assertFalse($guard->validate(['email' => 'nobody@example.com', 'password' => 'Secret-Passw0rd!']));
        $this->assertTrue($guard->guest());
    }

    public function testTheGateAnswersWithKintaiPermissions(): void
    {
        $gate = new Gate(new IlluminateContainer(), fn() => new KintaiUser(self::USER));
        $gate->before(KintaiGate::before(...));

        $this->assertTrue($gate->allows('shifts.update', [3]));
        $this->assertFalse($gate->allows('shifts.update', [4]));
        $this->assertFalse($gate->allows('employees.delete', [3]));
        // Capacité hors catalogue : laissée aux règles Laravel (aucune ici).
        $this->assertFalse($gate->allows('something-else'));
    }

    public function testAGuestIsDenied(): void
    {
        $gate = new Gate(new IlluminateContainer(), fn() => null);
        $gate->before(KintaiGate::before(...));

        $this->assertFalse($gate->allows('shifts.update', [3]));
    }
}
