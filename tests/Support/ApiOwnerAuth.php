<?php

declare(strict_types=1);

namespace kintai\Tests\Support;

use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * Fabrique un PermissionService qui voit l'appelant comme Owner (rôle système, portée
 * globale) : les tests d'API qui ne portent pas sur le périmètre magasin n'ont pas à
 * monter leurs propres rôles. Le Owner a l'id 1.
 */
final class ApiOwnerAuth
{
    public const OWNER_ID = 1;

    public static function permissions(TestCase $test): PermissionService
    {
        $assignments = self::mock($test, RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturn([
            ['id' => 1, 'user_id' => self::OWNER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        $assignments->method('findByScope')->willReturn([
            ['id' => 1, 'user_id' => self::OWNER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);

        $roles = self::mock($test, RoleRepositoryInterface::class);
        $roles->method('findById')->willReturn(['id' => 10, 'is_system' => 1]);
        $roles->method('getGlobalPermissionKeys')->willReturn([]);

        return new PermissionService($assignments, $roles);
    }

    /** createStub() est protégé : on l'appelle par réflexion depuis le test appelant. */
    private static function mock(TestCase $test, string $class): object
    {
        return (new \ReflectionMethod($test, 'createStub'))->invoke($test, $class);
    }

    /** Authentifie la requête comme l'Owner. */
    public static function owner(Request $request): Request
    {
        $request->setAttribute('auth_user', ['id' => self::OWNER_ID]);
        return $request;
    }
}
