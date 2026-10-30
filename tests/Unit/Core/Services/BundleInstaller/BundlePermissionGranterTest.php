<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Services\BundleInstaller;

use App\Auth\PermissionCatalog;
use App\Bundles\Bundle;
use App\Models\Role;
use App\Services\BundleInstaller\BundlePermissionGranter;
use PHPUnit\Framework\TestCase;

final class BundlePermissionGranterTest extends TestCase
{
    public function testDeclaredPermissionsFollowTheRolesPageRules(): void
    {
        // Catégorie avec une action mal formée écartée en entier (comme la page Rôles) ; catégories du Core et
        // catégorie déjà prise par un autre bundle jamais accordées par ce bundle.
        $this->assertSame(
            ['photos' => ['view', 'upload']],
            BundlePermissionGranter::declaredPermissions(GranterFixtureBundle::class, [...PermissionCatalog::CORE_CATEGORIES, 'timeoff']),
        );
    }

    public function testGrantAddsOnlyMissingKeysAndKeepsTheGlobalScope(): void
    {
        kintai_test_roles(getPermissions: fn(int $id) => $id === 2 ? ['photos.view', 'shifts.view'] : [], getGlobalPermissionKeys: fn(int $id) => $id === 2 ? ['shifts.view'] : []);

        $granted = (new BundlePermissionGranter())->grantToManager(['photos' => ['view', 'upload']]);

        $this->assertSame(['photos.upload'], $granted);
        $this->assertEqualsCanonicalizing(['photos.view', 'shifts.view', 'photos.upload'], Role::getPermissions(2));
        $this->assertSame(['shifts.view'], Role::getGlobalPermissionKeys(2), 'la portée globale est conservée');
    }

    public function testGrantSavesNothingWhenTheManagerAlreadyHasEverything(): void
    {
        kintai_test_roles(getPermissions: fn(int $id) => $id === 2 ? ['photos.view'] : []);

        $this->assertSame([], (new BundlePermissionGranter())->grantToManager(['photos' => ['view']]));
        $this->assertSame(['photos.view'], Role::getPermissions(2), 'rien n\'est réécrit');
    }

    public function testGrantDoesNothingWithoutAManagerRole(): void
    {
        kintai_test_ready('roles');
        \Illuminate\Support\Facades\DB::table('role_assignments')->delete();
        \Illuminate\Support\Facades\DB::table('roles')->where('slug', 'manager')->delete();
        \App\Database\RequestMemo::flush();

        $this->assertSame([], (new BundlePermissionGranter())->grantToManager(['photos' => ['view']]));
    }
}

/** Bundle factice : une seule catégorie recevable (« photos »), les autres illustrent chaque règle d'exclusion. */
final class GranterFixtureBundle extends Bundle
{
    public function getName(): string
    {
        return 'granter-fixture';
    }

    public function register(): void
    {
    }

    public function permissions(): array
    {
        return [
            'photos'       => ['view', 'upload', 'view'],
            'reports'      => ['view', 'Bad Action'],
            'Bad-Category' => ['view'],
            'empty'        => [],
            'stores'       => ['delete'],
            'timeoff'      => ['approve'],
        ];
    }
}
