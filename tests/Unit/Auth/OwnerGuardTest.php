<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Auth;

use kintai\Core\Auth\OwnerGuard;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Garde « au moins un Owner actif, et un Owner ne retire pas ses propres droits » : sans elle, un Owner pouvait
 * se supprimer (API comprise) et laisser l'instance sans administrateur.
 */
final class OwnerGuardTest extends TestCase
{
    /**
     * @param array<int, array{active?: bool, deleted?: bool}> $owners Owners par id ; les comptes absents ne sont pas Owner
     */
    private function guard(array $owners): OwnerGuard
    {
        $assignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByScope')->willReturn(array_map(
            fn(int $id): array => ['id' => $id, 'user_id' => $id, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
            array_keys($owners)
        ));
        $roles = $this->createStub(RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): ?array => isset($owners[$id])
            ? ['id' => $id, 'is_active' => ($owners[$id]['active'] ?? true) ? 1 : 0, 'deleted_at' => ($owners[$id]['deleted'] ?? false) ? '2026-01-01 00:00:00' : null]
            : ['id' => $id, 'is_active' => 1, 'deleted_at' => null]);

        return new OwnerGuard(new PermissionService($assignments, $roles), $users);
    }

    public function testNonOwnerTargetIsNeverBlocked(): void
    {
        $this->guard([1 => []])->assertCanRemoveOwner(1, 42);
        $this->addToAssertionCount(1);
    }

    public function testAnOwnerCannotRemoveTheirOwnAccount(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(__('error_owner_cannot_remove_self'));
        $this->guard([1 => [], 2 => []])->assertCanRemoveOwner(1, 1);
    }

    public function testAnotherOwnerCanBeRemovedWhileOneRemains(): void
    {
        $this->guard([1 => [], 2 => []])->assertCanRemoveOwner(1, 2);
        $this->addToAssertionCount(1);
    }

    public function testTheLastActiveOwnerCannotBeRemoved(): void
    {
        // Le seul autre Owner est désactivé : retirer le premier laisserait l'instance sans Owner utilisable.
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(__('error_last_owner'));
        $this->guard([2 => [], 3 => ['active' => false]])->assertCanRemoveOwner(99, 2);
    }

    public function testDeletedOwnersDoNotCountAsActive(): void
    {
        $guard = $this->guard([1 => [], 2 => ['deleted' => true], 3 => ['active' => false]]);

        $this->assertSame([1], $guard->activeOwnerIds());
    }

    public function testAnInactiveOwnerCanBeRemovedWhileAnActiveOneRemains(): void
    {
        $this->guard([1 => [], 3 => ['active' => false]])->assertCanRemoveOwner(1, 3);
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Actions d'un gestionnaire sur lui-même (audit du 13/10/2026, M13)
    // -------------------------------------------------------------------------

    public function testANonOwnerCannotActOnThemselves(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error_cannot_act_on_self');
        $this->guard([1 => []])->assertNotSelfUnlessOwner(5, 5);
    }

    public function testAnOwnerMayActOnThemselvesAndAnyoneMayActOnOthers(): void
    {
        $guard = $this->guard([1 => []]);
        $guard->assertNotSelfUnlessOwner(1, 1);
        $guard->assertNotSelfUnlessOwner(5, 6);
        $this->addToAssertionCount(2);
    }
}
