<?php

namespace Kintai\Bundles\DailyReport\Tests;

use App\Bundles\BundleManager;
use App\Bundles\DefaultPermissionGrant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Bundles\DailyReport\Models\DailyReportSetting;
use LogicException;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\Concerns\UsesApi;
use Tests\TestCase;

/**
 * API des rapports journaliers : `/api/v1/daily-report/reports`, mêmes droits que les écrans, mêmes erreurs que l'API du Core.
 */
class DailyReportApiTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;
    use UsesApi;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->a = Store::factory()->create(['name' => 'Alpha']);
        $this->b = Store::factory()->create(['name' => 'Zulu']);
        $this->enable();
    }

    private function enable(): void
    {
        $this->enableBundle('daily-report');
        app(DefaultPermissionGrant::class)->grantOnFirstActivation(app(BundleManager::class)->find('daily-report') ?? throw new LogicException);
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
    private function report(Store $store, ?User $author = null, string $status = 'draft', array $attributes = []): DailyReport
    {
        return DailyReport::create([
            'store_id' => $store->id, 'author_id' => $author?->id, 'report_date' => '2026-11-01', 'sales_total' => 1000,
            'customer_count' => 10, 'status' => $status, ...$attributes,
        ]);
    }

    private function today(): string
    {
        return Carbon::now((string) config('kintai.timezone'))->toDateString();
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/daily-report/reports')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->assertSame(url('/api/v1/daily-report/reports'), route('api.v1.daily-report.reports.index'));
    }

    public function test_the_list_is_scoped_like_the_screens_and_filtered(): void
    {
        $viewer = $this->holder(['daily_reports.view'], $this->a);
        $employee = $this->employeeOf($this->a);
        $this->report($this->a, $employee, 'draft', ['report_date' => '2026-11-01']);
        $this->report($this->a, null, 'validated', ['report_date' => '2026-11-02']);
        $this->report($this->b, $this->employeeOf($this->b), 'draft', ['report_date' => '2026-11-03']);

        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports')->assertJsonCount(2, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports?status=validated')->assertJsonCount(1, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports?date=2026-11-01')->assertJsonCount(1, 'data')->assertJsonPath('data.0.store.name', 'Alpha');
        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports?from=2026-11-02&to=2026-11-30')->assertJsonCount(1, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports?store_id='.$this->b->id)->assertJsonCount(0, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports?author_id='.$employee->id)->assertJsonCount(1, 'data');
        $this->actingWithToken($viewer)->getJson('/api/v1/daily-report/reports?status=bogus')->assertStatus(422)->assertJson(['code' => 'validation_failed']);

        $this->actingWithToken($employee)->getJson('/api/v1/daily-report/reports')->assertJsonCount(1, 'data');
    }

    public function test_show_hides_reports_out_of_scope(): void
    {
        $employee = $this->employeeOf($this->a);
        $own = $this->report($this->a, $employee);
        $colleague = $this->report($this->a, $this->employeeOf($this->a), 'draft', ['report_date' => '2026-11-05']);
        $foreign = $this->report($this->b, $this->employeeOf($this->b), 'draft', ['report_date' => '2026-11-06']);

        $this->actingWithToken($employee)->getJson('/api/v1/daily-report/reports/'.$own->id)->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can_edit', false);
        $this->actingWithToken($employee)->getJson('/api/v1/daily-report/reports/'.$colleague->id)->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->actingWithToken($this->holder(['daily_reports.view'], $this->a))->getJson('/api/v1/daily-report/reports/'.$foreign->id)->assertNotFound();
    }

    public function test_create_imposes_author_store_and_state_and_ignores_forbidden_fields(): void
    {
        $author = $this->holder(['daily_reports.create'], $this->a);

        $response = $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', [
            'id' => 999, 'store_id' => $this->a->id, 'report_date' => $this->today(), 'sales_total' => 5000.5, 'customer_count' => 12,
            'notes' => 'API note', 'status' => 'validated', 'author_id' => 1, 'validated_by' => 1,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.author.id', $author->id);

        $report = DailyReport::findOrFail($response->json('data.id'));
        $this->assertNotSame(999, $report->id);
        $this->assertSame([$this->a->id, $author->id, 'draft', null, 12, 'API note'], [$report->store_id, $report->author_id, $report->status, $report->validated_by, $report->customer_count, $report->notes]);
        $this->assertSame('5000.50', $report->sales_total);
    }

    public function test_create_is_validated_and_scoped(): void
    {
        $author = $this->holder(['daily_reports.create'], $this->a);
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id, 'report_date' => 'tomorrow'])->assertStatus(422);
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id, 'report_date' => $this->today(), 'customer_count' => -1])->assertStatus(422);
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->b->id, 'report_date' => $this->today()])->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingWithToken($this->employeeOf($this->a))->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id, 'report_date' => $this->today()])->assertForbidden();
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id, 'report_date' => '2020-01-01'])->assertStatus(422);

        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id, 'report_date' => $this->today()])->assertCreated();
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports', ['store_id' => $this->a->id, 'report_date' => $this->today()])->assertStatus(422);
    }

    public function test_update_only_changes_content(): void
    {
        $author = $this->holder(['daily_reports.create'], $this->a);
        $report = $this->report($this->a, $author);

        $this->actingWithToken($author)->putJson('/api/v1/daily-report/reports/'.$report->id, [
            'sales_total' => 777, 'notes' => 'Fixed', 'status' => 'validated', 'store_id' => $this->b->id, 'report_date' => '2030-01-01', 'author_id' => 1,
        ])->assertOk()->assertJsonPath('data.notes', 'Fixed');

        $fresh = $report->fresh();
        $this->assertSame(['777.00', 'draft', $this->a->id, '2026-11-01', $author->id], [$fresh->sales_total, $fresh->status, $fresh->store_id, $fresh->report_date->toDateString(), $fresh->author_id]);

        $this->actingWithToken($this->employeeOf($this->a))->putJson('/api/v1/daily-report/reports/'.$report->id, ['notes' => 'x'])->assertNotFound();
        $report->update(['status' => 'validated']);
        $this->actingWithToken($author)->putJson('/api/v1/daily-report/reports/'.$report->id, ['notes' => 'x'])->assertForbidden();
    }

    public function test_the_workflow_goes_through_the_api(): void
    {
        Mail::fake();
        $author = $this->holder(['daily_reports.create', 'daily_reports.submit'], $this->a);
        $validator = $this->holder(['daily_reports.approve', 'daily_reports.view'], $this->a);
        $report = $this->report($this->a, $author);

        $this->actingWithToken($validator)->postJson('/api/v1/daily-report/reports/'.$report->id.'/validate')->assertStatus(422);
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports/'.$report->id.'/validate')->assertForbidden();
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports/'.$report->id.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingWithToken($author)->postJson('/api/v1/daily-report/reports/'.$report->id.'/submit')->assertStatus(422);
        $this->actingWithToken($validator)->postJson('/api/v1/daily-report/reports/'.$report->id.'/validate')->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.validated_by', $validator->id);

        $this->actingWithToken($validator)->postJson('/api/v1/daily-report/reports/'.$report->id.'/send-mail')->assertStatus(422);
        $recipient = $this->employeeOf($this->a, ['email' => 'r@example.test']);
        DailyReportSetting::query()->firstOrNew(['store_id' => $this->a->id], DailyReportSetting::DEFAULTS)->fill(['mail_recipient_ids' => [$recipient->id]])->save();
        $this->actingWithToken($validator)->postJson('/api/v1/daily-report/reports/'.$report->id.'/send-mail')->assertOk();
        $this->assertNotNull($report->fresh()->mail_sent_at);
    }

    public function test_delete_needs_the_delete_permission(): void
    {
        $report = $this->report($this->a);

        $this->actingWithToken($this->holder(['daily_reports.view', 'daily_reports.update'], $this->a))->deleteJson('/api/v1/daily-report/reports/'.$report->id)->assertForbidden();
        $this->actingWithToken($this->holder(['daily_reports.delete', 'daily_reports.view'], $this->a))->deleteJson('/api/v1/daily-report/reports/'.$report->id)->assertNoContent();
        $this->assertSame(0, DailyReport::count());
    }
}
