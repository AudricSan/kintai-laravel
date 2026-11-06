<?php

namespace Kintai\Bundles\SalaryReport\Tests;

use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API des rapports de salaire : `/api/v1/salary-report/reports`, une permission par endpoint, mêmes droits que les écrans,
 * aucun accès hors des magasins où l'on a `salary_reports.view`, mêmes erreurs que l'API du Core.
 */
class SalaryReportApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

    private const BASE = '/api/v1/salary-report/reports';

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Zulu']);
        $type = ShiftType::factory()->create(['starts_at' => '09:00', 'ends_at' => '17:00', 'hourly_rate' => 12.50]);
        $type->stores()->attach([$this->a->id, $this->b->id]);
        $this->enableBundle('salary-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('salary-report') ?? throw new LogicException);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holder(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $store->members()->attach($user);
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(Store $store, ?User $employee = null, array $attributes = []): SalaryReport
    {
        return SalaryReport::create(['store_id' => $store->id, 'user_id' => $employee?->id, 'target_month' => '2026-10', 'staff_total_payment' => 200000, ...$attributes]);
    }

    public function test_every_endpoint_needs_a_token(): void
    {
        $report = $this->report($this->a);

        $this->getJson(self::BASE)->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->getJson(self::BASE.'/'.$report->id)->assertUnauthorized();
        $this->getJson(self::BASE.'/calculate')->assertUnauthorized();
        $this->postJson(self::BASE, [])->assertUnauthorized();
        $this->putJson(self::BASE.'/'.$report->id, [])->assertUnauthorized();
        $this->deleteJson(self::BASE.'/'.$report->id)->assertUnauthorized();
        $this->assertSame(url(self::BASE), route('api.v1.salary-report.reports.index'));
    }

    public function test_the_list_is_scoped_to_the_stores_with_view_and_filtered(): void
    {
        $viewer = $this->holder(['salary_reports.view'], $this->a);
        $ana = $this->employeeOf($this->a);
        $this->report($this->a, null, ['target_month' => '2026-10']);
        $this->report($this->a, $ana, ['target_month' => '2026-09']);
        $this->report($this->b, null, ['target_month' => '2026-10']);

        $this->actingWithToken($viewer)->getJson(self::BASE)->assertOk()->assertJsonCount(2, 'data');
        $this->actingWithToken($viewer)->getJson(self::BASE.'?month=2026-09')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $ana->id);
        $this->actingWithToken($viewer)->getJson(self::BASE.'?employee_id='.$ana->id)->assertJsonCount(1, 'data');
        $this->actingWithToken($viewer)->getJson(self::BASE.'?year=2026')->assertJsonCount(2, 'data');
        $this->actingWithToken($viewer)->getJson(self::BASE.'?store_id='.$this->b->id)->assertJsonCount(0, 'data');
        $this->actingWithToken($viewer)->getJson(self::BASE.'?month=bogus')->assertStatus(422)->assertJson(['code' => 'validation_failed']);

        // Sans la permission : liste vide, jamais les salaires.
        $this->actingWithToken($this->employeeOf($this->a))->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data');
        $this->actingWithToken($this->holder(['salary_reports.generate', 'salary_reports.export'], $this->a))->getJson(self::BASE)->assertJsonCount(0, 'data');
        $this->actingWithToken($this->managerOf($this->a))->getJson(self::BASE)->assertJsonCount(2, 'data');
    }

    public function test_show_hides_reports_out_of_scope_and_logs_the_view(): void
    {
        $own = $this->report($this->a);
        $foreign = $this->report($this->b);
        $viewer = $this->holder(['salary_reports.view'], $this->a);

        $this->actingWithToken($viewer)->getJson(self::BASE.'/'.$own->id)->assertOk()->assertJsonPath('data.target_month', '2026-10')
            ->assertJsonPath('data.staff_total_payment', '200000.00')->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_delete', false)->assertJsonPath('data.can_export', false)
            ->assertJsonMissingPath('data.employee.email');
        $this->actingWithToken($viewer)->getJson(self::BASE.'/'.$foreign->id)->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($this->employeeOf($this->a))->getJson(self::BASE.'/'.$own->id)->assertNotFound();
        $this->actingWithToken($this->holder(['salary_reports.generate'], $this->a))->getJson(self::BASE.'/'.$own->id)->assertNotFound();
        $this->assertSame(1, ActivityEntry::where('action', 'salary_report.viewed')->count());
    }

    public function test_calculate_needs_generate_on_the_store(): void
    {
        $ana = $this->employeeOf($this->a);
        Shift::fromLocalTimes('2026-10-05', '09:00', '17:00', ['store_id' => $this->a->id, 'user_id' => $ana->id, 'pause_minutes' => 60])->save();
        $query = '?store_id='.$this->a->id.'&from=2026-10-01&to=2026-10-31';

        $generator = $this->holder(['salary_reports.generate'], $this->a);
        $this->actingWithToken($generator)->getJson(self::BASE.'/calculate'.$query)->assertOk()
            ->assertJsonPath('data.preset.staff_man_hours', 7)->assertJsonPath('data.preset.staff_total_payment', 87.5)->assertJsonPath('data.detail.mode', 'store');
        $this->actingWithToken($generator)->getJson(self::BASE.'/calculate'.$query.'&employee_id='.$ana->id)->assertOk()->assertJsonPath('data.detail.mode', 'employee');

        $this->actingWithToken($this->holder(['salary_reports.view', 'salary_reports.export', 'salary_reports.delete', 'salary_reports.settings'], $this->a))
            ->getJson(self::BASE.'/calculate'.$query)->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($this->employeeOf($this->a))->getJson(self::BASE.'/calculate'.$query)->assertForbidden();
        $this->actingWithToken($generator)->getJson(self::BASE.'/calculate?store_id='.$this->b->id.'&from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->actingWithToken($generator)->getJson(self::BASE.'/calculate?store_id='.$this->a->id.'&from=2026-10-31&to=2026-10-01')->assertStatus(422);
        $this->actingWithToken($generator)->getJson(self::BASE.'/calculate'.$query.'&employee_id='.$this->employeeOf($this->b)->id)->assertStatus(422);
        $this->actingWithToken($generator)->getJson(self::BASE.'/calculate')->assertStatus(422);
    }

    public function test_create_imposes_store_creator_and_ignores_forbidden_fields(): void
    {
        $generator = $this->holder(['salary_reports.generate'], $this->a);
        $ana = $this->employeeOf($this->a);

        $response = $this->actingWithToken($generator)->postJson(self::BASE, [
            'id' => 999, 'store_id' => $this->a->id, 'month' => '2026-10', 'employee_id' => $ana->id, 'total_payment' => 1500.5, 'remarks' => 'API remark',
            'created_by' => 1, 'user_id' => 1, 'target_month' => '2000-01',
        ])->assertCreated()->assertJsonPath('data.employee.id', $ana->id);

        $report = SalaryReport::findOrFail($response->json('data.id'));
        $this->assertNotSame(999, $report->id);
        $this->assertSame([$this->a->id, $ana->id, '2026-10', $generator->id, 'API remark'], [$report->store_id, $report->user_id, $report->target_month, $report->created_by, $report->remarks]);
        $this->assertSame('1500.50', $report->total_payment);
        // Le journal ne garde ni montant ni remarque.
        $this->assertStringNotContainsString('1500', (string) json_encode(ActivityEntry::query()->pluck('properties')));
        $this->assertStringNotContainsString('API remark', (string) json_encode(ActivityEntry::query()->pluck('properties')));
    }

    public function test_create_is_validated_scoped_and_unique(): void
    {
        $generator = $this->holder(['salary_reports.generate'], $this->a);
        $payload = ['store_id' => $this->a->id, 'month' => '2026-10'];

        $this->actingWithToken($generator)->postJson(self::BASE, ['store_id' => $this->a->id])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->actingWithToken($generator)->postJson(self::BASE, ['store_id' => $this->a->id, 'month' => '2026-13'])->assertStatus(422);
        $this->actingWithToken($generator)->postJson(self::BASE, $payload + ['total_payment' => -1])->assertStatus(422);
        $this->actingWithToken($generator)->postJson(self::BASE, $payload + ['new_hires' => 1.5])->assertStatus(422);
        $this->actingWithToken($generator)->postJson(self::BASE, ['store_id' => $this->b->id, 'month' => '2026-10'])->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($this->holder(['salary_reports.view', 'salary_reports.export', 'salary_reports.delete'], $this->a))->postJson(self::BASE, $payload)->assertForbidden();
        $this->actingWithToken($this->employeeOf($this->a))->postJson(self::BASE, $payload)->assertForbidden();
        $this->actingWithToken($generator)->postJson(self::BASE, $payload + ['employee_id' => $this->employeeOf($this->b)->id])->assertStatus(422);
        $this->actingWithToken($generator)->postJson(self::BASE, $payload + ['person_in_charge_id' => $this->employeeOf($this->a)->id])->assertStatus(422);

        $this->assertSame(0, SalaryReport::count());
        $this->actingWithToken($generator)->postJson(self::BASE, $payload)->assertCreated();
        $this->actingWithToken($generator)->postJson(self::BASE, $payload)->assertStatus(422);
        $this->assertSame(1, SalaryReport::count());
    }

    public function test_update_needs_view_and_generate_and_never_moves_the_report(): void
    {
        $ana = $this->employeeOf($this->a);
        $report = $this->report($this->a, $ana);
        $editor = $this->holder(['salary_reports.view', 'salary_reports.generate'], $this->a);

        $this->actingWithToken($editor)->putJson(self::BASE.'/'.$report->id, [
            'total_payment' => 900, 'store_id' => $this->b->id, 'user_id' => 1, 'target_month' => '2000-01', 'month' => '2000-01',
        ])->assertOk()->assertJsonPath('data.total_payment', '900.00')->assertJsonPath('data.can_edit', true);

        $report->refresh();
        $this->assertSame([$this->a->id, $ana->id, '2026-10'], [$report->store_id, $report->user_id, $report->target_month]);

        $this->actingWithToken($this->holder(['salary_reports.view'], $this->a))->putJson(self::BASE.'/'.$report->id, ['total_payment' => 1])->assertForbidden();
        $this->actingWithToken($this->holder(['salary_reports.generate'], $this->a))->putJson(self::BASE.'/'.$report->id, ['total_payment' => 1])->assertNotFound();
        $this->actingWithToken($this->holder(['salary_reports.view', 'salary_reports.generate'], $this->b))->putJson(self::BASE.'/'.$report->id, ['total_payment' => 1])->assertNotFound();
        $this->actingWithToken($this->employeeOf($this->a))->putJson(self::BASE.'/'.$report->id, ['total_payment' => 1])->assertNotFound();
        $this->actingWithToken($editor)->putJson(self::BASE.'/'.$report->id, ['net_payment' => 'abc'])->assertStatus(422);
        $this->assertSame('900.00', $report->fresh()->total_payment);
    }

    public function test_destroy_needs_its_own_permission(): void
    {
        $report = $this->report($this->a);

        $this->actingWithToken($this->holder(['salary_reports.view', 'salary_reports.generate', 'salary_reports.export', 'salary_reports.settings'], $this->a))
            ->deleteJson(self::BASE.'/'.$report->id)->assertForbidden();
        $this->actingWithToken($this->holder(['salary_reports.delete'], $this->a))->deleteJson(self::BASE.'/'.$report->id)->assertNotFound();
        $this->actingWithToken($this->holder(['salary_reports.view', 'salary_reports.delete'], $this->b))->deleteJson(self::BASE.'/'.$report->id)->assertNotFound();
        $this->assertSame(1, SalaryReport::count());

        $this->actingWithToken($this->holder(['salary_reports.view', 'salary_reports.delete'], $this->a))->deleteJson(self::BASE.'/'.$report->id)->assertNoContent();
        $this->assertSame(0, SalaryReport::count());
    }
}
