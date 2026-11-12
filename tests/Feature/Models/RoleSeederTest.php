<?php

namespace Tests\Feature\Models;

use App\Authorization\CorePermissions;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(3, Role::count());
        $manager = Role::where('slug', 'manager')->firstOrFail();
        $this->assertSame(14, $manager->permissions()->count());
        $this->assertTrue($manager->is_manager);
        $this->assertTrue(Role::where('slug', 'owner')->firstOrFail()->is_system);
        $this->assertSame(0, Role::where('slug', 'employee')->firstOrFail()->permissions()->count());
    }

    public function test_manager_defaults_are_a_subset_of_core_permissions(): void
    {
        $this->assertCount(16, CorePermissions::all());
        $this->assertCount(14, CorePermissions::MANAGER_DEFAULTS);
        $this->assertSame([], array_diff(CorePermissions::MANAGER_DEFAULTS, CorePermissions::all()));
        $this->assertSame(['stores.create', 'stores.delete'], array_values(array_diff(CorePermissions::all(), CorePermissions::MANAGER_DEFAULTS)));
    }
}
