<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KintaiLegacy\Importer;
use KintaiLegacy\MigrationException;
use KintaiLegacy\Report;
use KintaiLegacy\Source;
use KintaiLegacy\Target;
use KintaiLegacy\Tests\Support\SyntheticSource;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/** Tranche 2c : reprise des fiches de paie, des retenues par magasin et des employés soumis aux retenues. */
class LegacySalaryMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBundle('salary-report');
        $this->legacy = new SyntheticSource;
        $this->storage = sys_get_temp_dir().'/kintai-target-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->legacy->cleanup();
        @rmdir($this->storage.'/avatars');
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function migrate(?Source $source = null, bool $dryRun = false): Report
    {
        $target = new Target(DB::connection()->getPdo(), 'sqlite', $this->storage);

        return (new Importer($source ?? $this->legacy->source(), $target, 'Asia/Tokyo', $dryRun))->run();
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        return DB::table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function total(): int
    {
        return DB::table('salary_reports')->count() + DB::table('salary_report_settings')->count() + DB::table('salary_report_members')->count();
    }

    private function hasNote(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, $needle));
    }

    private function hasWarning(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->warnings, fn (string $n): bool => str_contains($n, $needle));
    }

    public function test_salary_reports_keep_their_ids_and_exact_amounts(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 7, 9], $this->ids('salary_reports'));
        $one = DB::table('salary_reports')->find(1);
        $this->assertSame(1, (int) $one->store_id);
        $this->assertNull($one->user_id);
        $this->assertSame('2026-03', $one->target_month);
        $this->assertSame(2, (int) $one->person_in_charge_id);
        $this->assertSame('1234567.89', number_format((float) $one->total_payment, 2, '.', ''));
        $this->assertSame('200000.50', number_format((float) $one->total_deductions, 2, '.', ''));
        $this->assertSame('1000000.25', number_format((float) $one->income_tax_base, 2, '.', ''));
        $this->assertSame('12345.67', number_format((float) $one->withholding_tax, 2, '.', ''));
        $this->assertSame('5000.00', number_format((float) $one->residence_tax, 2, '.', ''));
        $this->assertSame('100.25', number_format((float) $one->other_deductions, 2, '.', ''));
        $this->assertSame('1034467.14', number_format((float) $one->net_payment, 2, '.', ''));
        $this->assertSame('50000.00', number_format((float) $one->hand_delivered_salary, 2, '.', ''));
        $this->assertSame('984467.14', number_format((float) $one->bank_transfer_salary, 2, '.', ''));
        $this->assertSame('160.50', number_format((float) $one->staff_man_hours, 2, '.', ''));
        $this->assertSame('1200000.00', number_format((float) $one->staff_total_payment, 2, '.', ''));
        $this->assertSame('1250.75', number_format((float) $one->staff_avg_hourly_wage, 2, '.', ''));
        $this->assertSame(3, (int) $one->active_employees);
        $this->assertSame(1, (int) $one->new_hires);
        $this->assertSame(0, (int) $one->resigned_staff);
        $this->assertSame('Eve', $one->hire_registrations);
        $this->assertSame('RAS', $one->remarks);
        $this->assertSame(2, (int) $one->created_by);
        $this->assertNull($one->deduction_lines);
        $this->assertSame('2026-03-31 09:00:00', $one->created_at);
        $this->assertSame('2026-04-01 00:00:00', $one->updated_at);

        // Fiche individuelle : son employé est conservé, un responsable inconnu et un créateur absent deviennent null.
        $two = DB::table('salary_reports')->find(2);
        $this->assertSame(3, (int) $two->user_id);
        $this->assertNull($two->person_in_charge_id);
        $this->assertNull($two->created_by);
        // Montants absents : 0 (valeur par défaut de l'ancienne colonne), pas de calcul.
        $seven = DB::table('salary_reports')->find(7);
        $this->assertSame(2, (int) $seven->store_id);
        $this->assertEquals(0, $seven->total_payment);

        $this->assertSame(10, $report->count('salary_reports', 'lues'));
        $this->assertSame(4, $report->count('salary_reports'));
        $this->assertSame(6, $report->count('salary_reports', 'ignorees'));
    }

    public function test_person_in_charge_is_set_only_for_a_single_user_with_that_exact_name(): void
    {
        $report = $this->migrate();

        $this->assertSame(2, (int) DB::table('salary_reports')->find(1)->person_in_charge_id);
        // Nom inconnu : null avec un avertissement agrégé ; texte vide : rien à reprendre, pas d'avertissement.
        $this->assertNull(DB::table('salary_reports')->find(2)->person_in_charge_id);
        $this->assertContains('salary_reports : 1 responsable(s) (person_in_charge) sans utilisateur du même nom, valeur perdue (null)', $report->warnings);
        $this->assertFalse($this->hasWarning($report, 'porté par plusieurs utilisateurs'));
    }

    public function test_ambiguous_person_in_charge_becomes_null_with_a_warning(): void
    {
        $this->legacy->pdo->exec('UPDATE "users" SET "display_name" = \'Max Manager\' WHERE "id" = 3');

        $report = $this->migrate();

        $this->assertNull(DB::table('salary_reports')->find(1)->person_in_charge_id);
        $this->assertTrue($this->hasWarning($report, '1 responsable(s) (person_in_charge) dont le nom est porté par plusieurs utilisateurs'));
        $this->assertTrue($this->hasWarning($report, '1 responsable(s) (person_in_charge) sans utilisateur du même nom'));
    }

    public function test_employee_hours_are_kept_only_when_valid_json_of_the_expected_shape(): void
    {
        $report = $this->migrate();

        $this->assertSame([['user_id' => 3, 'minutes' => 480], ['user_id' => 2, 'minutes' => 90]], json_decode((string) DB::table('salary_reports')->find(1)->employee_hours, true));
        // Texte libre (le format réel de l'ancienne version), employé inconnu, forme inconnue : jamais reconstruits.
        foreach ([2, 7, 9] as $id) {
            $this->assertNull(DB::table('salary_reports')->find($id)->employee_hours, (string) $id);
        }
        $this->assertTrue($this->hasWarning($report, 'salary_reports : 3 champ(s) employee_work_hours'));
    }

    public function test_duplicates_invalid_months_orphans_and_unreadable_rows_are_skipped(): void
    {
        $report = $this->migrate();

        // 3 : doublon global ; 10 : doublon individuel ; 4 : mois invalide ; 5 : magasin absent ; 6 : employé absent ; 8 : montant illisible.
        $this->assertSame([1, 2, 7, 9], $this->ids('salary_reports'));
        $this->assertContains('salary_reports : fiche 3 en double (même magasin, même mois, même employé), ignorée', $report->warnings);
        $this->assertContains('salary_reports : fiche 10 en double (même magasin, même mois, même employé), ignorée', $report->warnings);
        $this->assertContains('salary_reports : fiche 4 au mois « 2026-13 » illisible (AAAA-MM attendu), ignorée', $report->warnings);
        $this->assertContains("salary_reports : fiche 6 d'un employé absent de la source, ignorée (elle ne devient pas une fiche du magasin)", $report->warnings);
        $this->assertContains('salary_reports : fiche 8 au champ « total_payment » illisible, ignorée', $report->warnings);
        $this->assertContains('salary_reports : ligne orpheline (référence absente de la source), ignorée', $report->warnings);
        // Même mois dans un autre magasin : repris. Un employé de plus (9) dans le même mois : repris.
        $this->assertSame(2, (int) DB::table('salary_reports')->find(7)->store_id);
    }

    public function test_a_negative_count_makes_the_row_ignored_rather_than_corrected(): void
    {
        $this->legacy->pdo->exec('UPDATE "salary_reports" SET "new_hires" = -1 WHERE "id" = 9');

        $report = $this->migrate();

        $this->assertSame([1, 2, 7], $this->ids('salary_reports'));
        $this->assertContains('salary_reports : fiche 9 au champ « new_hires » illisible, ignorée', $report->warnings);
    }

    public function test_deduction_settings_become_one_row_per_store(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2], DB::table('salary_report_settings')->orderBy('store_id')->pluck('store_id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('salary_report_settings')->where('store_id', 1)->first();
        $this->assertSame(1, (int) $one->deductions_enabled);
        $this->assertSame('5.50', number_format((float) $one->health_insurance_rate, 2, '.', ''));
        $this->assertSame('9.15', number_format((float) $one->pension_rate, 2, '.', ''));
        $this->assertSame('0.60', number_format((float) $one->employment_insurance_rate, 2, '.', ''));
        $this->assertSame('3.20', number_format((float) $one->income_tax_rate, 2, '.', ''));
        $this->assertSame('1500.00', number_format((float) $one->resident_tax_monthly, 2, '.', ''));
        $this->assertSame('2026-03-01 00:00:00', $one->created_at);
        $two = DB::table('salary_report_settings')->where('store_id', 2)->first();
        $this->assertSame(0, (int) $two->deductions_enabled);
        $this->assertSame('4.99', number_format((float) $two->health_insurance_rate, 2, '.', ''));

        $this->assertSame(3, $report->count('salary_report_settings', 'lues'));
        $this->assertSame(2, $report->count('salary_report_settings'));
        $this->assertSame(1, $report->count('salary_report_settings', 'ignorees'));
    }

    public function test_an_unreadable_rate_ignores_the_setting_instead_of_guessing(): void
    {
        $this->legacy->pdo->exec('UPDATE "store_deduction_settings" SET "pension_rate" = \'beaucoup\' WHERE "store_id" = 2');

        $report = $this->migrate();

        $this->assertSame([1], DB::table('salary_report_settings')->pluck('store_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($this->hasWarning($report, 'réglage du magasin 2 au taux « pension_rate » illisible'));
    }

    public function test_members_subject_to_deductions_come_from_store_user(): void
    {
        $report = $this->migrate();

        $rows = DB::table('salary_report_members')->orderBy('user_id')->get(['store_id', 'user_id', 'subject_to_deductions']);
        $this->assertSame([[1, 2, 1], [1, 3, 1]], $rows->map(fn ($r): array => [(int) $r->store_id, (int) $r->user_id, (int) $r->subject_to_deductions])->all());
        // Non soumis (0) : pas de ligne ; membre dont l'employé est absent : ignoré.
        $this->assertSame(3, $report->count('salary_report_members', 'lues'));
        $this->assertSame(2, $report->count('salary_report_members'));
        $this->assertSame(1, $report->count('salary_report_members', 'ignorees'));
    }

    public function test_source_without_salary_tables_skips_the_steps_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, $this->total());
        foreach (['salary_reports', 'store_deduction_settings'] as $table) {
            $this->assertTrue($this->hasNote($report, $table.' : absente de la source'), $table);
        }
        $this->assertArrayNotHasKey('salary_reports', $report->tables);
        $this->assertArrayNotHasKey('salary_report_settings', $report->tables);
        $this->assertArrayNotHasKey('salary_report_members', $report->tables);
    }

    public function test_steps_are_independent_when_one_source_table_is_missing(): void
    {
        $this->legacy->pdo->exec('DROP TABLE "store_deduction_settings"');

        $report = $this->migrate();

        $this->assertSame(4, DB::table('salary_reports')->count());
        $this->assertSame(0, DB::table('salary_report_settings')->count());
        $this->assertSame(2, DB::table('salary_report_members')->count());
        $this->assertTrue($this->hasNote($report, 'store_deduction_settings : absente de la source'));
    }

    public function test_source_without_the_subject_to_deductions_column_skips_the_members(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "store_user" DROP COLUMN "subject_to_deductions"');

        $report = $this->migrate();

        $this->assertSame(0, DB::table('salary_report_members')->count());
        $this->assertArrayNotHasKey('salary_report_members', $report->tables);
        $this->assertSame(4, DB::table('salary_reports')->count());
    }

    public function test_salary_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "salary_reports" DROP COLUMN "bank_transfer_salary"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('salary_reports.bank_transfer_salary', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_target_without_the_bundle_tables_skips_with_a_note(): void
    {
        Schema::drop('salary_report_members');
        Schema::drop('salary_report_settings');
        Schema::drop('salary_reports');

        $report = $this->migrate();

        $this->assertSame(10, $report->count('salary_reports', 'ignorees'));
        $this->assertTrue($this->hasNote($report, 'salary_reports : table absente de la cible'));
        $this->assertSame(3, $report->count('salary_report_settings', 'ignorees'));
        $this->assertTrue($this->hasNote($report, 'salary_report_settings : table absente de la cible'));
        $this->assertSame(3, $report->count('salary_report_members', 'ignorees'));
        $this->assertTrue($this->hasNote($report, 'salary_report_members : table absente de la cible'));
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, $this->total());
        $this->assertSame(4, $report->count('salary_reports'));
        $this->assertSame(2, $report->count('salary_report_settings'));
        $this->assertSame(2, $report->count('salary_report_members'));
    }

    public function test_the_import_is_atomic_when_the_target_is_not_empty(): void
    {
        $this->migrate();
        try {
            $this->migrate();
            $this->fail('La cible non vide aurait dû être refusée.');
        } catch (MigrationException) {
            $this->assertSame(4, DB::table('salary_reports')->count());
        }
    }
}
