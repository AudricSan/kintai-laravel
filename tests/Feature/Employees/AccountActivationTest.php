<?php

namespace Tests\Feature\Employees;

use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Kintai\Contracts\Employees\AccountActivation;
use Kintai\Contracts\Events\EmployeeUpdated;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Contrat `AccountActivation` : désactivation et réactivation d'un compte, garde-fous Owner, sessions et jetons révoqués.
 */
class AccountActivationTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
    }

    private function service(): AccountActivation
    {
        return app(AccountActivation::class);
    }

    public function test_an_automatic_deactivation_and_a_reactivation_change_the_state_once(): void
    {
        Event::fake([EmployeeUpdated::class]);
        $employee = $this->employeeOf($this->store);

        $this->assertTrue($this->service()->deactivate($employee->id));
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertFalse($this->service()->deactivate($employee->id));

        $this->assertTrue($this->service()->reactivate($employee->id));
        $this->assertTrue($employee->fresh()->is_active);
        $this->assertFalse($this->service()->reactivate($employee->id));
        $this->assertFalse($this->service()->reactivate(999999));
        $this->assertFalse($this->service()->deactivate(999999));

        Event::assertDispatchedTimes(EmployeeUpdated::class, 2);
    }

    public function test_deactivating_revokes_the_api_tokens(): void
    {
        $employee = $this->employeeOf($this->store);
        $employee->createToken('mobile');
        $this->assertSame(1, $employee->tokens()->count());

        $this->service()->deactivate($employee->id);

        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_the_last_active_owner_is_never_deactivated(): void
    {
        $owner = $this->owner();

        try {
            $this->service()->deactivate($owner->id);
            $this->fail('The last owner must be protected.');
        } catch (AuthorizationException) {
            $this->assertTrue($owner->fresh()->is_active);
        }

        $other = $this->owner();
        $this->assertTrue($this->service()->deactivate($owner->id));
        $this->assertTrue($other->fresh()->is_active);

        // L'autre est désormais le dernier : refusé, avec ou sans acteur.
        $this->expectException(AuthorizationException::class);
        $this->service()->deactivate($other->id, $other->id);
    }

    public function test_an_actor_needs_the_right_on_the_employee_and_cannot_deactivate_themselves(): void
    {
        $employee = $this->employeeOf($this->store);
        $manager = $this->managerOf($this->store);
        $stranger = $this->managerOf(Store::factory()->create());

        try {
            $this->service()->deactivate($employee->id, $stranger->id);
            $this->fail('A manager of another store must be refused.');
        } catch (AuthorizationException) {
            $this->assertTrue($employee->fresh()->is_active);
        }

        try {
            $this->service()->deactivate($manager->id, $manager->id);
            $this->fail('Nobody deactivates themselves.');
        } catch (AuthorizationException) {
            $this->assertTrue($manager->fresh()->is_active);
        }

        $this->assertTrue($this->service()->deactivate($employee->id, $manager->id));
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_a_manager_cannot_deactivate_an_owner(): void
    {
        $owner = $this->owner();
        $this->owner();
        $manager = $this->managerOf($this->store);
        $this->store->members()->syncWithoutDetaching([$owner->id]);

        $this->expectException(AuthorizationException::class);
        $this->service()->deactivate($owner->id, $manager->id);
    }
}
