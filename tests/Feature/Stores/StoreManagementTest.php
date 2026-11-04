<?php

namespace Tests\Feature\Stores;

use App\Events\StoreCreated;
use App\Events\StoreDeleted;
use App\Events\StoreUpdated;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create(['name' => 'Alpha', 'code' => 'AAA']);
        $this->storeB = Store::factory()->create(['name' => 'Bravo', 'code' => 'BBB']);
    }

    private function createStore(User $actor, string $code, string $name, array $fields = []): Testable
    {
        $this->actingAs($actor);

        $component = Livewire::test('pages::stores.form')->set('code', $code)->set('name', $name);
        foreach ($fields as $field => $value) {
            $component->set($field, $value);
        }

        return $component->call('save');
    }

    public function test_owner_creates_a_store_with_normalized_code_and_event(): void
    {
        Event::fake([StoreCreated::class]);

        $this->createStore($this->owner(), ' shop-1 ', 'Shop One', ['timezone' => 'Asia/Tokyo'])->assertHasNoErrors();

        $store = Store::where('name', 'Shop One')->firstOrFail();
        $this->assertSame('SHOP-1', $store->code);
        $this->assertSame('Asia/Tokyo', $store->timezone);
        $this->assertSame('EUR', $store->currency);
        Event::assertDispatched(StoreCreated::class, fn (StoreCreated $e): bool => $e->storeId === $store->id);
    }

    public function test_restricted_manager_cannot_create_or_delete_a_store(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->actingAs($manager);

        $this->get(route('stores.create'))->assertForbidden();
        Livewire::test('pages::stores.form')->assertForbidden();

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertOk()
            ->assertDontSee('data-test="delete-store-button"', false)
            ->call('delete')
            ->assertForbidden();

        $this->assertNotSoftDeleted($this->storeA);
    }

    public function test_role_with_all_stores_can_create_and_delete(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->customRole(['stores.view', 'stores.create', 'stores.delete'], ['stores.view', 'stores.create', 'stores.delete']));

        $this->createStore($user, 'NEW', 'New store')->assertHasNoErrors();

        $store = Store::where('code', 'NEW')->firstOrFail();
        Livewire::test('pages::stores.form', ['store' => $store])->call('delete')->assertRedirect(route('stores.index'));
        $this->assertSoftDeleted($store);
    }

    public function test_owner_edits_a_store_and_event_is_emitted(): void
    {
        Event::fake([StoreUpdated::class]);
        $this->actingAs($this->owner());

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertSet('code', 'AAA')
            ->set('name', 'Alpha 2')
            ->set('currency', 'JPY')
            ->set('currency_symbol_style', 'international')
            ->set('locale', 'fr')
            ->set('email', 'shop@example.com')
            ->set('phone', '+81 3 1234 5678')
            ->set('address_city', 'Tokyo')
            ->set('week_start_day', '7')
            ->set('low_staff_hour_start', '14')
            ->set('low_staff_hour_end', '')
            ->set('min_staff_per_day', '3')
            ->set('min_shift_minutes', '60')
            ->set('max_shift_minutes', '600')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $store = $this->storeA->fresh();
        $this->assertSame('Alpha 2', $store->name);
        $this->assertSame('JPY', $store->currency);
        $this->assertSame('international', $store->currency_symbol_style);
        $this->assertSame('fr', $store->locale);
        $this->assertSame('Tokyo', $store->address_city);
        $this->assertSame(7, $store->week_start_day);
        $this->assertSame(14, $store->low_staff_hour_start);
        $this->assertNull($store->low_staff_hour_end);
        $this->assertSame(3, $store->min_staff_per_day);
        $this->assertFalse($store->is_active);
        Event::assertDispatched(StoreUpdated::class, fn (StoreUpdated $e): bool => $e->storeId === $this->storeA->id);
    }

    public function test_owner_deletes_a_store_and_its_code_is_reusable(): void
    {
        Event::fake([StoreDeleted::class]);
        $this->actingAs($this->owner());
        Shift::factory()->open()->create(['store_id' => $this->storeA->id]);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->call('delete')
            ->assertRedirect(route('stores.index'));

        $this->assertSoftDeleted($this->storeA);
        $this->assertSame('DEL-'.$this->storeA->id.'-AAA', Store::withTrashed()->find($this->storeA->id)->code);
        $this->assertSame(1, Shift::count());
        Event::assertDispatched(StoreDeleted::class, fn (StoreDeleted $e): bool => $e->storeId === $this->storeA->id);

        $this->createStore($this->owner(), 'AAA', 'Alpha again')->assertHasNoErrors();
        $this->assertSame(1, Store::where('code', 'AAA')->count());
    }

    public function test_list_only_shows_the_stores_of_the_actor_and_supports_search(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::stores.index')
            ->assertSee('Alpha')
            ->assertDontSee('Bravo')
            ->assertDontSee('data-test="new-store-button"', false);

        $this->actingAs($this->owner());

        Livewire::test('pages::stores.index')
            ->assertSee('Alpha')
            ->assertSee('Bravo')
            ->assertSee('data-test="new-store-button"', false)
            ->set('search', 'BBB')
            ->assertDontSee('Alpha')
            ->assertSee('Bravo');
    }

    public function test_list_counts_members(): void
    {
        $this->employeeOf($this->storeA);
        $this->employeeOf($this->storeA);
        $this->actingAs($this->owner());

        $stores = Livewire::test('pages::stores.index')->viewData('stores');
        $this->assertSame(2, $stores->first()->members_count);
    }

    public function test_manager_cannot_open_another_store(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        $this->get(route('stores.edit', $this->storeA))->assertOk();
        $this->get(route('stores.edit', $this->storeB))->assertForbidden();
    }

    public function test_user_without_store_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->employeeOf($this->storeA));

        $this->get(route('stores.index'))->assertForbidden();
    }

    public function test_view_only_actor_sees_a_read_only_form(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['stores.view']), $this->storeA);
        $this->actingAs($viewer);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertOk()
            ->assertSee('data-test="read-only-notice"', false)
            ->assertDontSee('data-test="save-store-button"', false)
            ->set('name', 'Hacked')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Alpha', $this->storeA->fresh()->name);
    }

    public function test_save_rechecks_authorization_when_rights_are_lost_after_mount(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->actingAs($manager);

        $component = Livewire::test('pages::stores.form', ['store' => $this->storeA]);
        $manager->removeRole($this->role('manager'), $this->storeA);

        Event::fake([StoreUpdated::class]);
        $component->call('save')->assertForbidden();
        Event::assertNotDispatched(StoreUpdated::class);
    }

    public function test_code_is_validated_and_unique_among_live_stores(): void
    {
        $owner = $this->owner();

        $this->createStore($owner, 'a', 'Short')->assertHasErrors('code');
        $this->createStore($owner, 'bad code!', 'Bad')->assertHasErrors('code');
        $this->createStore($owner, str_repeat('A', 21), 'Long')->assertHasErrors('code');
        $this->createStore($owner, 'aaa', 'Duplicate')->assertHasErrors('code');
        $this->createStore($owner, 'OK', '')->assertHasErrors('name');

        $this->actingAs($owner);
        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->set('code', 'bbb')
            ->call('save')
            ->assertHasErrors('code');
        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->set('code', 'aaa')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_timezone_is_normalized(): void
    {
        $owner = $this->owner();

        $this->createStore($owner, 'TZ1', 'Tz one', ['timezone' => 'UTC+9'])->assertHasNoErrors();
        $this->createStore($owner, 'TZ2', 'Tz two', ['timezone' => 'JST'])->assertHasNoErrors();
        $this->createStore($owner, 'TZ3', 'Tz three', ['timezone' => ''])->assertHasNoErrors();

        $this->assertSame('Asia/Tokyo', Store::where('code', 'TZ1')->value('timezone'));
        $this->assertSame('Asia/Tokyo', Store::where('code', 'TZ2')->value('timezone'));
        $this->assertSame(config('app.timezone'), Store::where('code', 'TZ3')->value('timezone'));

        $this->createStore($owner, 'TZ4', 'Tz four', ['timezone' => 'Mars/Olympus'])->assertHasErrors('timezone');
        $this->assertDatabaseMissing('stores', ['code' => 'TZ4']);
    }

    public function test_currency_and_other_enumerations_are_validated(): void
    {
        $owner = $this->owner();

        $this->createStore($owner, 'CU1', 'Cur', ['currency' => 'XXX'])->assertHasErrors('currency');
        $this->createStore($owner, 'CU2', 'Cur', ['currency_symbol_style' => 'emoji'])->assertHasErrors('currency_symbol_style');
        $this->createStore($owner, 'CU3', 'Cur', ['locale' => 'xx'])->assertHasErrors('locale');
        $this->createStore($owner, 'CU4', 'Cur', ['phone' => 'abc'])->assertHasErrors('phone');
        $this->createStore($owner, 'CU5', 'Cur', ['email' => 'nope'])->assertHasErrors('email');
        $this->createStore($owner, 'CU6', 'Cur', ['week_start_day' => '8'])->assertHasErrors('week_start_day');
        $this->createStore($owner, 'CU7', 'Cur', ['low_staff_hour_start' => '24'])->assertHasErrors('low_staff_hour_start');
        $this->createStore($owner, 'CU8', 'Cur', ['min_staff_per_day' => '-1'])->assertHasErrors('min_staff_per_day');
        $this->createStore($owner, 'CU9', 'Cur', ['max_shift_minutes' => '1441'])->assertHasErrors('max_shift_minutes');
        $this->assertSame(0, Store::where('name', 'Cur')->count());
    }

    public function test_min_shift_cannot_exceed_max_shift(): void
    {
        $this->createStore($this->owner(), 'MM1', 'Min max', ['min_shift_minutes' => '500', 'max_shift_minutes' => '400'])
            ->assertHasErrors('max_shift_minutes');

        $this->createStore($this->owner(), 'MM2', 'Min max', ['min_shift_minutes' => '400', 'max_shift_minutes' => '400'])
            ->assertHasNoErrors();
    }

    public function test_features_default_to_enabled_and_only_registered_keys_are_written(): void
    {
        $this->assertTrue($this->storeA->hasFeature('shifts'));
        $this->assertTrue($this->storeA->hasFeature('anything'));

        DB::table('store_features')->insert(['store_id' => $this->storeA->id, 'feature' => 'removed-bundle', 'enabled' => false]);
        $this->actingAs($this->owner());

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertSet('features.shifts', true)
            ->set('features.shifts', false)
            ->set('features.removed-bundle', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->storeA->fresh()->hasFeature('shifts'));
        $this->assertDatabaseHas('store_features', ['store_id' => $this->storeA->id, 'feature' => 'removed-bundle', 'enabled' => false]);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertSet('features.shifts', false)
            ->set('features.shifts', true)
            ->call('save');

        $this->assertTrue($this->storeA->fresh()->hasFeature('shifts'));
    }

    public function test_sidebar_links_follow_permissions(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        $this->get(route('dashboard'))
            ->assertSee(route('stores.index'), false)
            ->assertSee(route('shift-types.index'), false);

        $this->actingAs($this->employeeOf($this->storeA));

        $this->get(route('dashboard'))
            ->assertDontSee(route('stores.index'), false)
            ->assertDontSee(route('shift-types.index'), false);
    }
}
