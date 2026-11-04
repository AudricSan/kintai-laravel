<?php

namespace Database\Seeders;

use App\Authorization\CorePermissions;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Rôles obligatoires du corps ; peut être relancé sans doublon.
     */
    public function run(): void
    {
        $this->role('owner', 'Owner', ['is_system' => true, 'color' => '#DC2626']);

        $manager = $this->role('manager', 'Manager', ['is_manager' => true, 'color' => '#2563EB']);
        foreach (CorePermissions::MANAGER_DEFAULTS as $permission) {
            $manager->permissions()->updateOrCreate(['permission' => $permission], ['all_stores' => false]);
        }
        $manager->permissions()->whereNotIn('permission', CorePermissions::MANAGER_DEFAULTS)->delete();

        $this->role('employee', 'Employee', ['color' => '#6B7280']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function role(string $slug, string $name, array $attributes): Role
    {
        return Role::updateOrCreate(['slug' => $slug], ['name' => $name] + $attributes);
    }
}
