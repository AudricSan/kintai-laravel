<?php

namespace Tests\Feature\Authorization;

use App\Actions\Employees\ResetEmployeePassword;
use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class AccountTakeoverTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    public function test_a_manager_cannot_reset_the_password_of_a_better_equipped_colleague(): void
    {
        $this->seedRoles();
        $store = Store::factory()->create();
        $manager = $this->managerOf($store);
        $stronger = $this->employeeOf($store);
        $stronger->assignRole($this->customRole(['stores.create', 'employees.update'], ['stores.create' => true]));

        $this->expectException(AuthorizationException::class);
        app(ResetEmployeePassword::class)->handle($manager, $stronger);
    }

    public function test_a_manager_can_reset_the_password_of_an_ordinary_employee(): void
    {
        $this->seedRoles();
        $store = Store::factory()->create();
        $manager = $this->managerOf($store);
        $employee = $this->employeeOf($store);

        app(ResetEmployeePassword::class)->handle($manager, $employee);

        $this->assertTrue($employee->fresh()->must_change_password ?? true);
    }
}
