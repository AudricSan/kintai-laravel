<?php

namespace Kintai\Bundles\Analytics\Tests;

use App\Dashboard\DashboardLayoutResolver;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kintai\Bundles\Analytics\StoreStatsExport;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\DashboardWidgetRegistry;
use Kintai\Contracts\Employees\EmployeeActionRegistry;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Stores\StoreActionRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

/**
 * Pages du bundle (statistiques du magasin, rapport et fiche d'employé, rentabilité), accès (`payroll.view`, portée
 * magasin), export par les exports du Core (`payroll.export`), widgets du tableau de bord et navigation.
 */
class AnalyticsPagesTest extends TestCase
{
    use CreatesAccess;
    use InstallsBundles;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private User $owner;

    private User $manager;

    private User $aiko;

    private User $bao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->enableBundle('analytics');
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        Cache::store('array')->flush();

        $this->a = Store::factory()->create(['name' => 'Alpha', 'currency' => 'JPY']);
        $this->b = Store::factory()->create(['name' => 'Beta', 'currency' => 'JPY']);
        $type = ShiftType::factory()->create(['starts_at' => '06:00', 'ends_at' => '22:00', 'hourly_rate' => 12.5]);
        $type->stores()->attach([$this->a->id, $this->b->id]);

        $this->owner = $this->owner();
        $this->manager = $this->managerOf($this->a);
        $this->aiko = $this->employeeOf($this->a, ['name' => 'Aiko Sato', 'first_name' => 'Aiko', 'last_name' => 'Sato']);
        $this->bao = $this->employeeOf($this->b, ['name' => 'Bao Chen', 'first_name' => 'Bao', 'last_name' => 'Chen']);

        $this->shift($this->a, $this->aiko, '2026-11-05');
        $this->shift($this->a, $this->aiko, '2026-11-06');
        $this->shift($this->a, $this->aiko, '2026-10-20');
        $this->shift($this->b, $this->bao, '2026-11-05');
    }

    private function shift(Store $store, User $user, string $date, string $start = '09:00', string $end = '17:00'): Shift
    {
        return Shift::factory()->create([
            'store_id' => $store->id, 'user_id' => $user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end), 'pause_minutes' => 60,
        ]);
    }

    /**
     * Gérant avec les droits donnés (sur un magasin), sans rien d'autre.
     *
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
     * @return list<string>
     */
    private function pages(): array
    {
        return [
            '/analytics?store='.$this->a->id,
            '/analytics/employees?store='.$this->a->id,
            '/analytics/employee?user='.$this->aiko->id.'&store='.$this->a->id,
            '/analytics/profitability?store='.$this->a->id,
        ];
    }

    // -- Accès ---------------------------------------------------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach ($this->pages() as $page) {
            $this->get($page)->assertRedirect(route('login'));
        }
    }

    public function test_without_payroll_view_every_page_is_forbidden(): void
    {
        $employee = $this->aiko;
        $holder = $this->holder(['employees.view', 'payroll.export'], $this->a);

        foreach ([$employee, $holder] as $user) {
            foreach ($this->pages() as $page) {
                $this->actingAs($user)->get($page)->assertForbidden();
            }
        }
    }

    public function test_a_restricted_manager_only_sees_their_own_store(): void
    {
        // `?store=` hors du périmètre : retombe sur le magasin du gérant, jamais sur celui de l'autre.
        $this->actingAs($this->manager)->get('/analytics?store='.$this->b->id)
            ->assertOk()
            ->assertSee('Alpha')
            ->assertSee('Aiko Sato')
            ->assertDontSee('Bao Chen')
            ->assertDontSee('Beta');

        $this->actingAs($this->manager)->get('/analytics/employees?store='.$this->b->id)->assertOk()->assertSee('Aiko Sato')->assertDontSee('Bao Chen');
        $this->actingAs($this->manager)->get('/analytics/employee?user='.$this->bao->id.'&store='.$this->b->id)->assertForbidden();
        $this->actingAs($this->manager)->get('/analytics/employee?user='.$this->bao->id)->assertForbidden();
        $this->actingAs($this->manager)->get('/analytics/employee?user=999999')->assertForbidden();
    }

    public function test_a_forged_store_is_refused_on_the_next_render(): void
    {
        $component = Livewire::actingAs($this->manager)->test('analytics.stats')->assertOk();

        $component->set('store', (string) $this->b->id)->assertForbidden();
    }

    public function test_the_owner_picks_any_store(): void
    {
        $this->actingAs($this->owner)->get('/analytics?store='.$this->b->id)->assertOk()->assertSee('Bao Chen')->assertDontSee('Aiko Sato');
        $this->actingAs($this->owner)->get('/analytics')->assertOk()->assertSee('data-test="store-select"', false);
    }

    // -- Statistiques du magasin ---------------------------------------------------------------------------------

    public function test_the_store_page_shows_the_figures_with_the_store_currency(): void
    {
        $response = $this->actingAs($this->manager)->get('/analytics');

        $response->assertOk()
            ->assertSee('Statistics - Alpha')
            ->assertSee('from 2026-10-12 to 2026-11-11')
            ->assertSee('Performance scores')
            ->assertSee('Shifts analyzed')
            ->assertSee('Aiko Sato')
            ->assertSee('data-test="section-financial"', false)
            ->assertSee('円')
            ->assertSee('data-test="weekly-hours"', false)
            // Aucun statut de congé ni type de congé : les blocs sans donnée ne s'affichent pas.
            ->assertDontSee('Leave by type')
            ->assertDontSee('Swaps');
    }

    public function test_the_period_is_chosen_and_bounded(): void
    {
        $this->actingAs($this->manager)->get('/analytics?period=7')->assertSee('from 2026-11-04 to 2026-11-11');
        $this->actingAs($this->manager)->get('/analytics?period=3')->assertSee('from 2026-11-04 to 2026-11-11');
        $this->actingAs($this->manager)->get('/analytics?period=9999')->assertSee('from 2025-11-11 to 2026-11-11');

        Livewire::actingAs($this->manager)->test('analytics.stats')->call('setPeriod', 90)->assertSet('period', 90)->assertSee('from 2026-08-13');
    }

    public function test_an_empty_store_says_so_without_zero_filled_sections(): void
    {
        $empty = Store::factory()->create(['name' => 'Gamma']);

        $this->actingAs($this->owner)->get('/analytics?store='.$empty->id)
            ->assertOk()
            ->assertSee('No shifts in this period.')
            ->assertDontSee('Performance scores');
    }

    public function test_a_store_without_rates_warns_that_the_cost_is_zero(): void
    {
        $free = Store::factory()->create(['name' => 'Free']);
        $this->shift($free, $this->owner, '2026-11-05');

        $this->actingAs($this->owner)->get('/analytics?store='.$free->id)->assertOk()->assertSee('data-test="no-rate-hint"', false);
    }

    public function test_labels_come_from_the_translations_in_the_user_language(): void
    {
        $this->aiko->forceFill(['locale' => 'ja'])->save();
        $this->manager->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($this->manager)->get('/analytics')->assertSee('Scores de performance')->assertDontSee('Performance scores');
    }

    public function test_the_export_link_needs_payroll_export(): void
    {
        $this->actingAs($this->manager)->get('/analytics')
            ->assertSee('data-test="export-link"', false)
            ->assertSee('dataset=store-stats', false);

        $viewer = $this->holder(['payroll.view'], $this->a);
        $this->actingAs($viewer)->get('/analytics')->assertOk()->assertDontSee('data-test="export-link"', false);
    }

    public function test_the_profitability_link_needs_a_sales_provider(): void
    {
        $this->actingAs($this->manager)->get('/analytics')->assertDontSee('data-test="profitability-link"', false);

        $this->enableBundle('daily-report');
        $this->actingAs($this->manager)->get('/analytics')->assertSee('data-test="profitability-link"', false);
    }

    // -- Rapport et fiche d'employé ------------------------------------------------------------------------------

    public function test_the_employee_report_lists_every_member_with_a_link_to_the_sheet(): void
    {
        $quiet = $this->employeeOf($this->a, ['name' => 'Quiet Person', 'first_name' => 'Quiet', 'last_name' => 'Person']);

        $response = $this->actingAs($this->manager)->get('/analytics/employees');

        $response->assertOk()
            ->assertSee('Employee report - Alpha')
            ->assertSee('Aiko Sato')
            ->assertSee('Quiet Person')
            ->assertSee('/analytics/employee?user='.$this->aiko->id, false)
            ->assertDontSee('Swaps');
        $this->assertSame(2, substr_count($response->getContent(), 'data-test="employee-row"'));
        $this->assertNotNull($quiet);
    }

    public function test_the_employee_sheet_shows_the_figures_and_records_the_view(): void
    {
        $this->actingAs($this->manager)->get('/analytics/employee?user='.$this->aiko->id)
            ->assertOk()
            ->assertSee('Statistics - Aiko Sato')
            ->assertSee('data-test="employee-kpis"', false)
            ->assertSee('data-test="recent-shifts"', false)
            ->assertDontSee('Swaps');

        $entry = ActivityEntry::query()->where('action', 'analytics.employee_viewed')->first();
        $this->assertNotNull($entry);
        $this->assertSame($this->manager->id, $entry->actor_id);
        $this->assertSame($this->a->id, $entry->store_id);
    }

    public function test_an_employee_of_several_stores_is_seen_through_a_store_the_viewer_can_view(): void
    {
        $this->b->members()->attach($this->aiko);

        // Le gérant de A ne voit que A ; le Owner choisit entre A et B.
        $this->actingAs($this->manager)->get('/analytics/employee?user='.$this->aiko->id.'&store='.$this->b->id)->assertOk()->assertSee('Alpha')->assertDontSee('Beta');
        $this->actingAs($this->owner)->get('/analytics/employee?user='.$this->aiko->id)->assertOk()->assertSee('data-test="store-select"', false);
    }

    public function test_the_employees_list_gets_a_statistics_action_for_viewers_with_payroll_view(): void
    {
        $registry = app(EmployeeActionRegistry::class);

        $labels = fn (User $viewer, int $employee): array => array_map(fn ($action): string => $action->key, $registry->actionsFor($viewer, $employee));

        $this->assertContains('analytics.statistics', $labels($this->manager, $this->aiko->id));
        $this->assertNotContains('analytics.statistics', $labels($this->manager, $this->bao->id));
        $this->assertNotContains('analytics.statistics', $labels($this->aiko, $this->aiko->id));
        $this->assertContains('analytics.statistics', $labels($this->owner, $this->bao->id));
    }

    public function test_the_store_list_gets_statistics_and_profitability_actions_by_rights(): void
    {
        $registry = app(StoreActionRegistry::class);
        $keys = fn (User $viewer, int $store): array => array_map(fn ($action): string => $action->key, $registry->actionsFor($viewer, $store));

        // Sans fournisseur de ventes, pas de rentabilité.
        $this->assertSame(['analytics.statistics'], $keys($this->manager, $this->a->id));
        $this->assertSame([], $keys($this->manager, $this->b->id));
        $this->assertSame([], $keys($this->aiko, $this->a->id));
        $this->assertSame(['analytics.statistics'], $keys($this->owner, $this->b->id));

        $this->enableBundle('daily-report');

        $this->assertSame(['analytics.statistics', 'analytics.profitability'], $keys($this->manager, $this->a->id));
        $this->assertSame([], $keys($this->manager, $this->b->id));
    }

    public function test_the_store_list_shows_the_analytics_links_with_the_store_in_the_query(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('pages::stores.index')
            ->assertSeeHtml('data-test="store-action-analytics.statistics"')
            ->assertSeeHtml(route('analytics.stats', ['store' => $this->a->id]))
            ->assertDontSeeHtml('data-test="store-action-analytics.profitability"');

        $this->enableBundle('daily-report');

        Livewire::test('pages::stores.index')
            ->assertSeeHtml('data-test="store-action-analytics.profitability"')
            ->assertSeeHtml(route('analytics.profitability', ['store' => $this->a->id]));

        // « Statistiques » ouvre bien la page du magasin choisi.
        $this->actingAs($this->owner)->get(route('analytics.stats', ['store' => $this->b->id]))->assertOk()->assertSee('Statistics - Beta');
    }

    public function test_a_manager_without_payroll_view_sees_no_analytics_link_on_the_store_list(): void
    {
        $this->actingAs($this->holder(['stores.view'], $this->a));

        Livewire::test('pages::stores.index')
            ->assertSee('Alpha')
            ->assertDontSeeHtml('data-test="store-action-analytics.');
    }

    // -- Rentabilité ---------------------------------------------------------------------------------------------

    public function test_without_a_sales_provider_the_profitability_page_explains_it(): void
    {
        $this->actingAs($this->manager)->get('/analytics/profitability')
            ->assertOk()
            ->assertSee('data-test="no-sales-provider"', false)
            ->assertDontSee('data-test="profitability-kpis"', false);
    }

    public function test_the_profitability_page_compares_sales_with_the_calculated_cost(): void
    {
        $this->enableBundle('daily-report');
        $this->report($this->a, '2026-11-05', 'validated', 1000, 200, 10);
        $this->report($this->a, '2026-11-06', 'submitted', 2000, 300, 20);
        $this->report($this->b, '2026-11-05', 'validated', 98765, 0, 1);

        $response = $this->actingAs($this->manager)->get('/analytics/profitability?from=2026-11-01&to=2026-11-10');

        $response->assertOk()
            ->assertSee('data-test="profitability-kpis"', false)
            ->assertSee('3,000円')
            ->assertSee('2026-11-05')
            ->assertSee('Validated')
            ->assertSee('Submitted')
            ->assertSee('data-test="total-row"', false)
            ->assertDontSee('98,765');
        $this->assertSame(2, substr_count($response->getContent(), 'data-test="day-row"'));
    }

    public function test_a_period_without_daily_reports_says_so(): void
    {
        $this->enableBundle('daily-report');

        $this->actingAs($this->manager)->get('/analytics/profitability?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertSee('data-test="no-daily-reports"', false);
    }

    public function test_profitability_dates_are_validated_ordered_and_bounded(): void
    {
        $this->enableBundle('daily-report');

        $component = Livewire::withQueryParams(['from' => '2026-11-10', 'to' => '2026-11-01'])->actingAs($this->manager)->test('analytics.profitability');
        $component->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-10');

        $component = Livewire::withQueryParams(['from' => 'garbage', 'to' => '2026-11-11'])->actingAs($this->manager)->test('analytics.profitability');
        $component->assertSet('from', '2026-08-13')->assertSet('to', '2026-11-11');

        $component = Livewire::withQueryParams(['from' => '2020-01-01', 'to' => '2026-11-11'])->actingAs($this->manager)->test('analytics.profitability');
        $component->assertSet('from', '2025-11-10');

        $component->call('setPreset', 30)->assertSet('from', '2026-10-12')->assertSet('to', '2026-11-11');
    }

    private function report(Store $store, string $date, string $status, float $sales, float $labor, int $customers): void
    {
        DailyReport::query()->create([
            'store_id' => $store->id, 'report_date' => $date, 'status' => $status,
            'sales_total' => $sales, 'labor_cost' => $labor, 'customer_count' => $customers,
        ]);
    }

    // -- Export --------------------------------------------------------------------------------------------------

    public function test_the_dataset_is_registered_for_the_core_exports(): void
    {
        $dataset = app(ExportDatasetRegistry::class)->find(StoreStatsExport::KEY);

        $this->assertInstanceOf(StoreStatsExport::class, $dataset);
        $this->assertSame('payroll.export', $dataset->permission());
        $this->assertFalse($dataset->selfService());
        $this->assertTrue($dataset->hasPeriod());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function export(User $user, array $parameters = [])
    {
        return $this->actingAs($user)->get(route('exports.download', $parameters + [
            'dataset' => StoreStatsExport::KEY, 'format' => 'csv', 'target' => 'stores', 'from' => '2026-11-01', 'to' => '2026-11-30',
        ]));
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function lines(string $body): array
    {
        return array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), preg_split('/\r?\n/', trim(substr($body, 3))));
    }

    public function test_the_export_has_one_line_per_employee_for_the_period_and_the_stores_of_the_manager(): void
    {
        $response = $this->export($this->manager);

        $response->assertOk();
        $lines = $this->lines($response->streamedContent());
        $this->assertSame(['Store', 'Employee', 'Net hours', 'Estimated cost', 'Currency'], $lines[0]);
        // Deux shifts de 7 h nettes en novembre à 12,5 ; celui d'octobre est hors période ; rien de l'autre magasin.
        $this->assertCount(2, $lines);
        $this->assertSame(['Alpha', 'Aiko Sato', '14.00', '175.00', 'JPY'], $lines[1]);
    }

    public function test_the_export_period_bounds_are_inclusive_and_exclude_other_days(): void
    {
        $lines = $this->lines($this->export($this->manager, ['from' => '2026-11-06', 'to' => '2026-11-06'])->streamedContent());
        $this->assertSame(['Alpha', 'Aiko Sato', '7.00', '87.50', 'JPY'], $lines[1]);

        $lines = $this->lines($this->export($this->manager, ['from' => '2026-10-20', 'to' => '2026-11-05'])->streamedContent());
        $this->assertSame('14.00', $lines[1][2]);

        $lines = $this->lines($this->export($this->manager, ['from' => '2026-12-01', 'to' => '2026-12-31'])->streamedContent());
        $this->assertCount(1, $lines);
    }

    public function test_the_owner_exports_every_store_and_can_filter_one(): void
    {
        $lines = $this->lines($this->export($this->owner)->streamedContent());
        $this->assertSame(['Alpha', 'Beta'], array_values(array_unique(array_column(array_slice($lines, 1), 0))));

        $lines = $this->lines($this->export($this->owner, ['stores' => [$this->b->id]])->streamedContent());
        $this->assertCount(2, $lines);
        $this->assertSame('Bao Chen', $lines[1][1]);
    }

    public function test_the_export_can_be_limited_to_some_employees(): void
    {
        $lines = $this->lines($this->export($this->owner, ['users' => [$this->bao->id]])->streamedContent());

        $this->assertCount(2, $lines);
        $this->assertSame('Bao Chen', $lines[1][1]);
    }

    public function test_the_export_is_refused_without_payroll_export_or_outside_the_stores(): void
    {
        $this->export($this->aiko)->assertForbidden();
        $this->export($this->holder(['payroll.view'], $this->a))->assertForbidden();
        $this->export($this->manager, ['stores' => [$this->b->id]])->assertForbidden();
    }

    public function test_the_export_works_in_the_other_formats(): void
    {
        foreach (['xlsx', 'pdf'] as $format) {
            $this->export($this->manager, ['format' => $format])->assertOk();
        }
    }

    // -- Widgets -------------------------------------------------------------------------------------------------

    public function test_the_three_widgets_are_offered_to_managers_with_payroll_view_only(): void
    {
        $keys = fn (User $user): array => array_map(fn (DashboardWidget $widget): string => $widget->key, app(DashboardLayoutResolver::class)->available($user, DashboardWidget::MANAGER));

        foreach (['analytics.stats', 'analytics.finance', 'analytics.absence'] as $key) {
            $this->assertTrue(app(DashboardWidgetRegistry::class)->has($key));
            $this->assertContains($key, $keys($this->manager));
            $this->assertNotContains($key, $keys($this->holder(['shifts.view'], $this->a)));
        }
    }

    public function test_the_stats_widget_is_limited_to_the_stores_of_the_manager(): void
    {
        Livewire::actingAs($this->manager)->test('analytics.stats-widget')
            ->assertSee('Alpha')
            ->assertDontSee('Beta')
            ->assertSee('Stats overview - 30 days')
            // Un seul magasin : la tendance hebdomadaire s'affiche.
            ->assertSee('data-test="widget-weekly-hours"', false);

        Livewire::actingAs($this->owner)->test('analytics.stats-widget')
            ->assertSee('Alpha')
            ->assertSee('Beta')
            ->assertDontSee('data-test="widget-weekly-hours"', false);
    }

    public function test_the_finance_widget_compares_the_month_with_the_previous_one(): void
    {
        // Novembre : 2 shifts (175), octobre : 1 shift (87,5) : +100 %.
        Livewire::actingAs($this->manager)->test('analytics.finance-widget')
            ->assertSee('175円')
            ->assertSee('88円')
            ->assertSee('+100% vs previous month')
            ->assertSee('data-test="widget-finance-group"', false);
    }

    public function test_the_finance_widget_never_adds_up_different_currencies(): void
    {
        $this->b->update(['currency' => 'EUR']);

        $component = Livewire::actingAs($this->owner)->test('analytics.finance-widget');

        $this->assertSame(2, substr_count($component->html(), 'data-test="widget-finance-group"'));
    }

    public function test_the_absence_widget_shows_the_rate_and_the_approved_leave(): void
    {
        Livewire::actingAs($this->manager)->test('analytics.absence-widget')
            ->assertSee('HR and absenteeism - 30 days')
            ->assertSeeHtml('data-test="absence-rate"')
            ->assertSee('Absence rate')
            ->assertDontSee('Late');
    }

    // -- Navigation ----------------------------------------------------------------------------------------------

    public function test_the_menu_offers_the_pages_to_viewers_and_profitability_only_with_sales(): void
    {
        $keys = fn (User $user): array => array_map(fn ($item): string => $item->key, app(NavigationRegistry::class)->visibleTo($user));

        $this->assertContains('analytics', $keys($this->manager));
        $this->assertContains('analytics-employees', $keys($this->manager));
        $this->assertNotContains('analytics-profitability', $keys($this->manager));
        $this->assertNotContains('analytics', $keys($this->aiko));

        $this->enableBundle('daily-report');
        $this->assertContains('analytics-profitability', $keys($this->manager));
        $this->assertNotContains('analytics-profitability', $keys($this->aiko));
    }
}
