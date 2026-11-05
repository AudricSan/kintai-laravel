<?php

namespace Tests\Feature\ShiftTypes;

use App\Actions\Employees\UpdateEmployeeRates;
use App\Actions\ShiftTypes\DeleteShiftType;
use App\Actions\ShiftTypes\SaveShiftType;
use App\Actions\ShiftTypes\ToggleShiftTypeStore;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftTypeCreated;
use Kintai\Contracts\Events\ShiftTypeDeleted;
use Kintai\Contracts\Events\ShiftTypeUpdated;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ShiftTypeTest extends TestCase
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

    /**
     * @param  list<Store>  $stores
     */
    private function typeFor(array $stores, array $attributes = []): ShiftType
    {
        $type = ShiftType::factory()->create($attributes);
        $type->stores()->attach(array_map(fn (Store $store): int => $store->id, $stores));

        return $type;
    }

    private function fillForm(Testable $component, string $code, string $name, array $storeIds, array $fields = []): Testable
    {
        $component->set('code', $code)->set('name', $name)->set('store_ids', array_map('strval', $storeIds));
        foreach ($fields as $field => $value) {
            $component->set($field, $value);
        }

        return $component->call('save');
    }

    public function test_manager_creates_a_shift_type_for_their_store(): void
    {
        Event::fake([ShiftTypeCreated::class]);
        $this->actingAs($this->managerOf($this->storeA));

        $this->fillForm(Livewire::test('pages::shift-types.form'), ' morning ', 'Morning', [$this->storeA->id], [
            'starts_at' => '06:00',
            'ends_at' => '14:30',
            'hourly_rate' => '12.5',
            'color' => '#ff0000',
            'sort_order' => '2',
        ])->assertHasNoErrors();

        $type = ShiftType::where('name', 'Morning')->firstOrFail();
        $this->assertSame('MORNING', $type->code);
        $this->assertSame('06:00', $type->starts_at);
        $this->assertSame('14:30', $type->ends_at);
        $this->assertSame('12.50', $type->hourly_rate);
        $this->assertSame(2, $type->sort_order);
        $this->assertSame([$this->storeA->id], $type->stores()->pluck('stores.id')->all());
        Event::assertDispatched(ShiftTypeCreated::class, fn (ShiftTypeCreated $e): bool => $e->shiftTypeId === $type->id);
    }

    public function test_defaults_are_applied(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('pages::shift-types.form')->assertSet('starts_at', '08:00')->assertSet('ends_at', '16:00');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'DEF', 'Defaults', [$this->storeA->id], ['starts_at' => '', 'ends_at' => ''])
            ->assertHasNoErrors();

        $type = ShiftType::where('code', 'DEF')->firstOrFail();
        $this->assertSame('08:00', $type->starts_at);
        $this->assertSame('16:00', $type->ends_at);
    }

    public function test_manager_cannot_attach_a_store_they_do_not_manage(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        $this->fillForm(Livewire::test('pages::shift-types.form'), 'X', 'Sneaky', [$this->storeA->id, $this->storeB->id])
            ->assertHasErrors('store_ids.1');

        $this->assertDatabaseMissing('shift_types', ['name' => 'Sneaky']);
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->owner());
        $ids = [$this->storeA->id];

        $this->fillForm(Livewire::test('pages::shift-types.form'), 'bad code', 'Bad', $ids)->assertHasErrors('code');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', '', $ids)->assertHasErrors('name');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', 'Name', [])->assertHasErrors('store_ids');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', 'Name', $ids, ['hourly_rate' => '-1'])->assertHasErrors('hourly_rate');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', 'Name', $ids, ['hourly_rate' => '100000000'])->assertHasErrors('hourly_rate');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', 'Name', $ids, ['color' => 'red'])->assertHasErrors('color');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', 'Name', $ids, ['sort_order' => '-3'])->assertHasErrors('sort_order');
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'OK', 'Name', $ids, ['starts_at' => '25:00'])->assertHasErrors('starts_at');
        $this->assertSame(0, ShiftType::count());
    }

    public function test_crossing_midnight_is_accepted_and_equal_times_are_refused(): void
    {
        $this->actingAs($this->owner());
        $ids = [$this->storeA->id];

        $this->fillForm(Livewire::test('pages::shift-types.form'), 'NIGHT', 'Night', $ids, ['starts_at' => '22:00', 'ends_at' => '06:00'])
            ->assertHasNoErrors();
        $this->fillForm(Livewire::test('pages::shift-types.form'), 'SAME', 'Same', $ids, ['starts_at' => '08:00', 'ends_at' => '08:00'])
            ->assertHasErrors('ends_at');

        $this->assertDatabaseHas('shift_types', ['code' => 'NIGHT']);
        $this->assertDatabaseMissing('shift_types', ['code' => 'SAME']);
    }

    public function test_manager_edits_their_own_type_and_event_is_emitted(): void
    {
        Event::fake([ShiftTypeUpdated::class]);
        $type = $this->typeFor([$this->storeA]);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::shift-types.form', ['shiftType' => $type])
            ->assertSet('code', $type->code)
            ->set('name', 'Renamed')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $type->fresh()->name);
        $this->assertFalse($type->fresh()->is_active);
        Event::assertDispatched(ShiftTypeUpdated::class, fn (ShiftTypeUpdated $e): bool => $e->shiftTypeId === $type->id);
    }

    public function test_shared_type_with_an_unmanaged_store_is_locked_for_edit_and_delete(): void
    {
        $shared = $this->typeFor([$this->storeA, $this->storeB], ['name' => 'Shared']);
        $manager = $this->managerOf($this->storeA);
        $this->actingAs($manager);

        $this->get(route('shift-types.edit', $shared))->assertForbidden();

        Livewire::test('pages::shift-types.index')
            ->assertSee('Shared')
            ->assertDontSee('data-test="edit-shift-type-button"', false);

        $this->expectException(AuthorizationException::class);
        app(DeleteShiftType::class)->handle($manager, $shared);
    }

    public function test_shared_type_cannot_be_saved_by_an_unrelated_manager(): void
    {
        $shared = $this->typeFor([$this->storeA, $this->storeB]);
        $manager = $this->managerOf($this->storeA);

        $this->expectException(AuthorizationException::class);
        app(SaveShiftType::class)->handle($manager, $shared, [
            'code' => 'X', 'name' => 'Y', 'store_ids' => [$this->storeA->id],
        ]);
    }

    public function test_toggle_per_store_is_allowed_for_a_shared_type(): void
    {
        Event::fake([ShiftTypeUpdated::class]);
        $shared = $this->typeFor([$this->storeA, $this->storeB]);
        $manager = $this->managerOf($this->storeA);
        $this->actingAs($manager);

        Livewire::test('pages::shift-types.index')
            ->call('toggleStore', $shared->id, $this->storeA->id)
            ->assertHasNoErrors();

        $this->assertSame([$this->storeB->id], $shared->stores()->pluck('stores.id')->all());
        Event::assertDispatched(ShiftTypeUpdated::class);

        // Le magasin voisin n'est pas celui du gérant.
        $this->expectException(AuthorizationException::class);
        app(ToggleShiftTypeStore::class)->handle($manager, $shared, $this->storeB);
    }

    public function test_toggle_can_reattach_a_store_and_never_removes_the_last_one(): void
    {
        $type = $this->typeFor([$this->storeA]);
        $owner = $this->owner();
        $toggle = app(ToggleShiftTypeStore::class);

        $toggle->handle($owner, $type, $this->storeB);
        $this->assertEqualsCanonicalizing([$this->storeA->id, $this->storeB->id], $type->stores()->pluck('stores.id')->all());

        $toggle->handle($owner, $type, $this->storeA);
        $this->assertSame([$this->storeB->id], $type->stores()->pluck('stores.id')->all());

        try {
            $toggle->handle($owner, $type, $this->storeB);
            $this->fail('The last store must be kept.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('store', $e->errors());
        }

        $this->assertSame([$this->storeB->id], $type->stores()->pluck('stores.id')->all());
    }

    public function test_deleting_is_refused_when_shifts_use_the_type_even_soft_deleted(): void
    {
        Event::fake([ShiftTypeDeleted::class]);
        $type = $this->typeFor([$this->storeA]);
        $shift = Shift::factory()->open()->create(['store_id' => $this->storeA->id, 'shift_type_id' => $type->id]);
        $shift->delete();
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::shift-types.index')
            ->call('confirmDeletion', $type->id)
            ->call('delete')
            ->assertHasErrors('delete');

        $this->assertModelExists($type);
        Event::assertNotDispatched(ShiftTypeDeleted::class);
    }

    public function test_unused_type_is_deleted_for_good(): void
    {
        Event::fake([ShiftTypeDeleted::class]);
        $type = $this->typeFor([$this->storeA]);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::shift-types.index')
            ->call('confirmDeletion', $type->id)
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertModelMissing($type);
        $this->assertDatabaseMissing('shift_type_store', ['shift_type_id' => $type->id]);
        Event::assertDispatched(ShiftTypeDeleted::class, fn (ShiftTypeDeleted $e): bool => $e->shiftTypeId === $type->id);
    }

    public function test_list_is_filtered_by_store_and_sorted(): void
    {
        $this->typeFor([$this->storeA], ['name' => 'Zulu', 'sort_order' => 1]);
        $this->typeFor([$this->storeA], ['name' => 'Yankee', 'sort_order' => 0]);
        $this->typeFor([$this->storeB], ['name' => 'Hidden']);
        $this->typeFor([$this->storeA, $this->storeB], ['name' => 'Both', 'sort_order' => 5]);

        $this->actingAs($this->managerOf($this->storeA));
        Livewire::test('pages::shift-types.index')
            ->assertSeeInOrder(['Yankee', 'Zulu', 'Both'])
            ->assertDontSee('Hidden');

        $this->actingAs($this->owner());
        Livewire::test('pages::shift-types.index')->assertSee('Hidden');
    }

    public function test_view_only_actor_sees_the_list_without_actions_and_cannot_open_the_form(): void
    {
        $type = $this->typeFor([$this->storeA], ['name' => 'Visible']);
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['shifts.view']), $this->storeA);
        $this->actingAs($viewer);

        Livewire::test('pages::shift-types.index')
            ->assertSee('Visible')
            ->assertDontSee('data-test="new-shift-type-button"', false)
            ->assertDontSee('data-test="delete-shift-type-button"', false);

        $this->get(route('shift-types.create'))->assertForbidden();
        $this->get(route('shift-types.edit', $type))->assertForbidden();
    }

    public function test_employee_without_shift_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->employeeOf($this->storeA));

        $this->get(route('shift-types.index'))->assertForbidden();
    }

    public function test_employee_rate_list_only_offers_active_types_of_common_stores(): void
    {
        $active = $this->typeFor([$this->storeA], ['name' => 'Offered']);
        $this->typeFor([$this->storeA], ['name' => 'Disabled', 'is_active' => false]);
        $this->typeFor([$this->storeB], ['name' => 'Elsewhere']);
        $employee = $this->employeeOf($this->storeA);

        $offered = app(UpdateEmployeeRates::class)
            ->eligibleShiftTypes($this->managerOf($this->storeA), $employee);

        $this->assertSame([$active->id], $offered->modelKeys());
    }
}
