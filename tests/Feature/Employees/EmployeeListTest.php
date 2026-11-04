<?php

namespace Tests\Feature\Employees;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class EmployeeListTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create(['name' => 'Alpha']);
        $this->storeB = Store::factory()->create(['name' => 'Bravo']);
    }

    public function test_user_without_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->employeeOf($this->storeA));

        $this->get(route('employees.index'))->assertForbidden();
        $this->get(route('employees.create'))->assertForbidden();
    }

    public function test_manager_only_sees_members_of_their_stores(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->storeA->members()->attach($manager);
        $mine = $this->employeeOf($this->storeA, ['name' => 'Mine Colleague']);
        $other = $this->employeeOf($this->storeB, ['name' => 'Other Store Person']);
        $orphan = User::factory()->create(['name' => 'Orphan Account']);

        $this->actingAs($manager);

        Livewire::test('pages::employees.index')
            ->assertSee('Mine Colleague')
            ->assertDontSee('Other Store Person')
            ->assertDontSee('Orphan Account');

        $this->get(route('employees.edit', $mine))->assertOk();
        $this->get(route('employees.edit', $other))->assertForbidden();
        $this->get(route('employees.edit', $orphan))->assertForbidden();
    }

    public function test_owner_sees_everyone_including_accounts_without_store(): void
    {
        $this->employeeOf($this->storeA, ['name' => 'Mine Colleague']);
        $this->employeeOf($this->storeB, ['name' => 'Other Store Person']);
        User::factory()->create(['name' => 'Orphan Account']);
        $owner = $this->owner();
        $owner->update(['name' => 'Chief Owner']);

        $this->actingAs($owner);

        Livewire::test('pages::employees.index')
            ->assertSee('Mine Colleague')
            ->assertSee('Other Store Person')
            ->assertSee('Orphan Account')
            ->assertSee('Chief Owner')
            ->set('store', 'none')
            ->assertSee('Orphan Account')
            ->assertDontSee('Mine Colleague');
    }

    public function test_anonymized_accounts_are_not_listed(): void
    {
        $this->employeeOf($this->storeA, ['name' => 'Gone Person'])->delete();

        $this->actingAs($this->owner());

        Livewire::test('pages::employees.index')->assertDontSee('Gone Person');
    }

    public function test_store_filter_is_limited_to_the_actor_stores(): void
    {
        $manager = $this->managerOf($this->storeA);
        $other = $this->employeeOf($this->storeB, ['name' => 'Other Store Person']);
        $this->storeA->members()->attach($other);

        $this->actingAs($manager);

        // Un magasin étranger ou « sans magasin » n'élargit jamais la liste d'un gérant restreint.
        Livewire::test('pages::employees.index')
            ->set('store', (string) $this->storeB->id)
            ->assertSee('Other Store Person')
            ->set('store', 'none')
            ->assertSee('Other Store Person');
    }

    public function test_search_matches_names_furigana_email_and_code(): void
    {
        $this->employeeOf($this->storeA, ['name' => 'Alice Martin', 'first_name' => 'Alice', 'last_name' => 'Martin', 'furigana_last_name' => 'マルタン', 'email' => 'alice@example.com', 'employee_code' => 'A100']);
        $this->employeeOf($this->storeA, ['name' => 'Bob Durand', 'first_name' => 'Bob', 'last_name' => 'Durand', 'email' => 'bob@example.com', 'employee_code' => 'B200']);

        $this->actingAs($this->managerOf($this->storeA));

        foreach (['Alice', 'Martin', 'マルタン', 'alice@example', 'A100', 'a1'] as $term) {
            Livewire::test('pages::employees.index')->set('search', $term)->assertSee('Alice Martin')->assertDontSee('Bob Durand');
        }

        Livewire::test('pages::employees.index')->set('search', '%')->assertDontSee('Alice Martin')->assertDontSee('Bob Durand');
    }

    public function test_list_can_be_sorted_by_name_and_email(): void
    {
        $this->employeeOf($this->storeA, ['name' => 'Zed Last', 'email' => 'a@example.com']);
        $this->employeeOf($this->storeA, ['name' => 'Abe First', 'email' => 'z@example.com']);

        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.index')
            ->assertSeeInOrder(['Abe First', 'Zed Last'])
            ->call('sort', 'name')
            ->assertSeeInOrder(['Zed Last', 'Abe First'])
            ->call('sort', 'email')
            ->assertSeeInOrder(['Zed Last', 'Abe First'])
            ->call('sort', 'email')
            ->assertSeeInOrder(['Abe First', 'Zed Last']);
    }

    public function test_list_is_paginated_by_25(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $this->employeeOf($this->storeA, ['name' => sprintf('Person %02d', $i)]);
        }

        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.index')
            ->assertSee('Person 25')
            ->assertDontSee('Person 26');
    }
}
