<?php

namespace Tests\Feature\Shifts;

use App\Actions\Availability\SaveAvailability;
use App\Models\Availability;
use App\Models\Store;
use App\Models\User;
use App\Planning\AvailabilityGuard;
use App\Profile\ProfileSectionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->employee = $this->employeeOf($this->store);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function week(array $unavailable = [], array $ranges = []): array
    {
        $days = [];
        foreach (range(1, 7) as $day) {
            $days[$day] = ['available' => ! in_array($day, $unavailable, true)] + ($ranges[$day] ?? []);
        }

        return $days;
    }

    // --- AvailabilityGuard (cas de l'ancienne version) ---

    public function test_a_day_marked_unavailable_is_reported(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1]);

        // 2026-10-26 est un lundi.
        $this->assertSame(1, app(AvailabilityGuard::class)->unavailableWeekday($this->employee->id, $this->store->id, '2026-10-26'));
    }

    public function test_sunday_is_seven(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 7]);

        $this->assertSame(7, app(AvailabilityGuard::class)->unavailableWeekday($this->employee->id, $this->store->id, '2026-10-25'));
    }

    public function test_an_available_day_is_not_reported(): void
    {
        Availability::factory()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1, 'is_available' => true]);

        $this->assertNull(app(AvailabilityGuard::class)->unavailableWeekday($this->employee->id, $this->store->id, '2026-10-26'));
    }

    public function test_another_weekday_is_not_reported(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 2]);

        $this->assertNull(app(AvailabilityGuard::class)->unavailableWeekday($this->employee->id, $this->store->id, '2026-10-26'));
    }

    public function test_nothing_is_reported_without_any_row(): void
    {
        $this->assertNull(app(AvailabilityGuard::class)->unavailableWeekday($this->employee->id, $this->store->id, '2026-10-26'));
    }

    public function test_invalid_input_is_ignored(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1]);
        $guard = app(AvailabilityGuard::class);

        $this->assertNull($guard->unavailableWeekday(0, $this->store->id, '2026-10-26'));
        $this->assertNull($guard->unavailableWeekday($this->employee->id, 0, '2026-10-26'));
        $this->assertNull($guard->unavailableWeekday($this->employee->id, $this->store->id, 'pas une date'));
    }

    public function test_the_guard_is_scoped_to_the_store_and_the_employee(): void
    {
        $other = Store::factory()->create();
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $other->id, 'day_of_week' => 1]);
        Availability::factory()->unavailable()->create(['store_id' => $this->store->id, 'day_of_week' => 1]);

        $this->assertNull(app(AvailabilityGuard::class)->unavailableWeekday($this->employee->id, $this->store->id, '2026-10-26'));
    }

    // --- SaveAvailability ---

    public function test_saving_replaces_the_seven_rows_for_the_store(): void
    {
        $other = Store::factory()->create();
        $other->members()->attach($this->employee);
        Availability::factory()->create(['user_id' => $this->employee->id, 'store_id' => $other->id, 'day_of_week' => 3]);

        $action = app(SaveAvailability::class);
        $action->handle($this->employee, $this->employee, $this->store, $this->week([1, 7], [2 => ['start' => '09:00', 'end' => '17:00']]));
        $action->handle($this->employee, $this->employee, $this->store, $this->week([2]));

        $rows = Availability::query()->where('store_id', $this->store->id)->where('user_id', $this->employee->id)->orderBy('day_of_week')->get();
        $this->assertCount(7, $rows);
        $this->assertFalse($rows[1]->is_available);
        $this->assertTrue($rows[0]->is_available);
        $this->assertNull($rows[1]->starts_at);
        $this->assertSame(1, Availability::where('store_id', $other->id)->count());
    }

    public function test_time_range_is_kept_for_an_available_day_only(): void
    {
        app(SaveAvailability::class)->handle($this->employee, $this->employee, $this->store, $this->week([2], [
            1 => ['start' => '09:00', 'end' => '17:30'],
            2 => ['start' => '09:00', 'end' => '17:30'],
        ]));

        $rows = Availability::query()->orderBy('day_of_week')->get()->keyBy('day_of_week');
        $this->assertSame('09:00', $rows[1]->starts_at);
        $this->assertSame('17:30', $rows[1]->ends_at);
        $this->assertNull($rows[2]->starts_at);
    }

    public function test_a_range_needs_both_bounds(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveAvailability::class)->handle($this->employee, $this->employee, $this->store, $this->week([], [1 => ['start' => '09:00']]));
    }

    public function test_an_employee_only_edits_their_own_availability(): void
    {
        $colleague = $this->employeeOf($this->store);

        try {
            app(SaveAvailability::class)->handle($this->employee, $colleague, $this->store, $this->week());
            $this->fail('Authorization expected');
        } catch (AuthorizationException) {
            $this->assertSame(0, Availability::count());
        }
    }

    public function test_even_an_owner_cannot_edit_someone_elses_availability(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveAvailability::class)->handle($this->owner(), $this->employee, $this->store, $this->week());
    }

    public function test_a_non_member_store_is_refused(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveAvailability::class)->handle($this->employee, $this->employee, Store::factory()->create(), $this->week());
    }

    // --- Page et section du profil ---

    public function test_the_section_is_in_the_profile_for_members_only(): void
    {
        $registry = app(ProfileSectionRegistry::class);
        $keys = fn (User $user): array => array_map(fn ($section) => $section->key, $registry->sections($user));

        $this->assertContains('availability', $keys($this->employee));
        $this->assertNotContains('availability', $keys(User::factory()->create()));
        $this->assertSame(['profile', 'availability', 'security', 'notifications', 'navigation', 'appearance'], $keys($this->employee));
    }

    public function test_the_page_shows_a_form_per_store_and_saves(): void
    {
        $this->actingAs($this->employee);

        $this->get(route('availability.edit'))->assertOk()->assertSee($this->store->name);

        Livewire::test('pages::settings.availability')
            ->set('days.'.$this->store->id.'.3.available', false)
            ->call('save', $this->store->id)
            ->assertHasNoErrors();

        $this->assertFalse(Availability::where('user_id', $this->employee->id)->where('day_of_week', 3)->firstOrFail()->is_available);
    }

    public function test_the_page_cannot_save_for_another_store(): void
    {
        $this->actingAs($this->employee);

        Livewire::test('pages::settings.availability')->call('save', Store::factory()->create()->id)->assertStatus(404);

        $this->assertSame(0, Availability::count());
    }
}
