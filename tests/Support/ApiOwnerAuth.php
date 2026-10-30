<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\PermissionService;
use App\Models\RoleAssignment;
use Illuminate\Http\Request;
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
        kintai_test_assignments(findByUser: fn() => [
            ['id' => 1, 'user_id' => self::OWNER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ], findByScope: fn() => [
            ['id' => 1, 'user_id' => self::OWNER_ID, 'role_id' => 10, 'scope_type' => 'global', 'scope_id' => null],
        ]);
        kintai_test_roles(findById: fn() => ['id' => 10, 'is_system' => 1]);

        return new PermissionService();
    }

    /** createStub() est protégé : on l'appelle par réflexion depuis le test appelant. */
    private static function mock(TestCase $test, string $class): object
    {
        return (new \ReflectionMethod($test, 'createStub'))->invoke($test, $class);
    }

    /** Authentifie la requête comme l'Owner. */
    public static function owner(Request $request): Request
    {
        $request->attributes->set('auth_user', ['id' => self::OWNER_ID]);
        return $request;
    }
}
