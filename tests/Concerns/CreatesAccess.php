<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Outils de test pour les rôles et les droits (rôles du seeder + rôles sur mesure).
 */
trait CreatesAccess
{
    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }

    protected function role(string $slug): Role
    {
        return Role::where('slug', $slug)->firstOrFail();
    }

    protected function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('owner'));

        return $user;
    }

    /**
     * Gérant (rôle `manager` du seeder) d'un seul magasin.
     */
    protected function managerOf(Store $store): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('manager'), $store);

        return $user;
    }

    /**
     * Rôle non système avec les permissions données ; `$allStores` liste celles qui ignorent la portée magasin.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $allStores
     */
    protected function customRole(array $permissions, array $allStores = []): Role
    {
        $role = Role::factory()->create();
        foreach ($permissions as $permission) {
            $role->permissions()->create(['permission' => $permission, 'all_stores' => in_array($permission, $allStores, true)]);
        }

        return $role;
    }
}
