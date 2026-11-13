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

/** Tranche 2b : reprise des rapports d'embauche, de démission et journaliers (et des réglages du rapport journalier). */
class LegacyReportsMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['hiring-report', 'resignation-report', 'daily-report'] as $slug) {
            $this->enableBundle($slug);
        }
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
        return DB::table('hiring_reports')->count() + DB::table('resignation_reports')->count()
            + DB::table('daily_reports')->count() + DB::table('daily_report_settings')->count();
    }

    public function test_hiring_reports_keep_their_ids_and_drop_missing_references(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2], $this->ids('hiring_reports'));
        $one = DB::table('hiring_reports')->find(1);
        $this->assertSame(3, (int) $one->user_id);
        $this->assertSame('エンプロイー', $one->furigana_last_name);
        $this->assertSame('イブ', $one->furigana_first_name);
        $this->assertSame('1995-02-03', $one->birth_date);
        $this->assertSame('2026-01-15', $one->hire_date);
        $this->assertSame('100-0001', $one->postal_code);
        $this->assertSame(2, (int) $one->created_by);
        $this->assertSame('2026-01-10 00:00:00', $one->created_at);
        $two = DB::table('hiring_reports')->find(2);
        $this->assertNull($two->user_id);
        $this->assertNull($two->created_by);
        $this->assertNull($two->birth_date);
        $this->assertNull($two->hire_date);

        $this->assertSame(3, $report->count('hiring_reports', 'lues'));
        $this->assertSame(2, $report->count('hiring_reports'));
        $this->assertSame(1, $report->count('hiring_reports', 'ignorees'));
    }

    public function test_resignation_reports_start_without_deactivation(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2], $this->ids('resignation_reports'));
        $one = DB::table('resignation_reports')->find(1);
        $this->assertSame(4, (int) $one->user_id);
        $this->assertSame('2026-05-31', $one->resignation_date);
        $this->assertSame('Préavis de 15 jours', $one->resignation_notice);
        $this->assertSame('Max', $one->person_in_charge);
        $this->assertNull($one->deactivated_at);
        $this->assertSame(2, (int) $one->created_by);
        $two = DB::table('resignation_reports')->find(2);
        $this->assertNull($two->user_id);
        $this->assertNull($two->resignation_date);
        $this->assertNull($two->created_by);

        $this->assertSame(3, $report->count('resignation_reports', 'lues'));
        $this->assertSame(1, $report->count('resignation_reports', 'ignorees'));
    }

    public function test_daily_reports_skip_duplicates_unknown_statuses_deleted_and_orphans(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 8], $this->ids('daily_reports'));
        $one = DB::table('daily_reports')->find(1);
        $this->assertSame('2026-03-02', $one->report_date);
        $this->assertSame('validated', $one->status);
        $this->assertEquals(123456.5, $one->sales_total);
        $this->assertSame(42, (int) $one->customer_count);
        $this->assertEquals(30000, $one->labor_cost);
        $this->assertSame(3, (int) $one->author_id);
        $this->assertSame(2, (int) $one->validated_by);
        $this->assertSame('2026-03-02 12:00:00', $one->submitted_at);
        $this->assertSame('2026-03-02 13:00:00', $one->validated_at);
        $this->assertSame('2026-03-02 13:01:00', $one->mail_sent_at);

        // Auteur et validateur absents : références mises à null ; montants illisibles : 0.
        $two = DB::table('daily_reports')->find(2);
        $this->assertNull($two->author_id);
        $this->assertNull($two->validated_by);
        $this->assertEquals(0, $two->sales_total);
        $this->assertSame(0, (int) $two->customer_count);
        // Même jour dans un autre magasin : repris.
        $this->assertSame(2, (int) DB::table('daily_reports')->find(8)->store_id);

        $this->assertSame(8, $report->count('daily_reports', 'lues'));
        $this->assertSame(3, $report->count('daily_reports'));
        $this->assertSame(5, $report->count('daily_reports', 'ignorees'));
        $this->assertContains('daily_reports : rapport 3 en double (même magasin, même jour), ignoré', $report->warnings);
        $this->assertContains('daily_reports : rapport 4 de statut « archived » inconnu, ignoré', $report->warnings);
        $this->assertContains('daily_reports : 1 montant(s) illisible(s) repris comme 0', $report->warnings);
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, '1 rapport(s) supprimé(s)')));
    }

    public function test_daily_report_settings_are_read_from_the_stores_json(): void
    {
        $report = $this->migrate();

        $this->assertSame([1], DB::table('daily_report_settings')->pluck('store_id')->map(fn ($id) => (int) $id)->all());
        $settings = DB::table('daily_report_settings')->where('store_id', 1)->first();
        $this->assertSame(1, (int) $settings->auto_send_on_validate);
        $this->assertSame('02:30', $settings->auto_validate_time);
        $this->assertSame('16:00', $settings->reminder_time);
        $this->assertSame('18:00', $settings->no_report_time); // illisible : valeur par défaut de la cible
        $this->assertSame(['employee', 'net_hours'], json_decode($settings->show_columns, true));
        $this->assertSame('cumulative_input', $settings->cumulative_mode);
        $this->assertSame([2, 3], json_decode($settings->mail_recipient_ids, true));

        // Le magasin 2 a un JSON illisible : valeurs par défaut de la cible.
        $this->assertSame(2, $report->count('daily_report_settings', 'lues'));
        $this->assertSame(1, $report->count('daily_report_settings', 'ignorees'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, '1 clé(s) de réglage sans équivalent')));
    }

    public function test_source_without_report_tables_skips_the_steps_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, $this->total());
        foreach (['hiring_reports', 'resignation_reports', 'daily_reports'] as $table) {
            $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_starts_with($n, $table.' : absente de la source')), $table);
            $this->assertArrayNotHasKey($table, $report->tables);
        }
        $this->assertArrayNotHasKey('daily_report_settings', $report->tables);
    }

    public function test_report_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "daily_reports" DROP COLUMN "mail_sent_at"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('daily_reports.mail_sent_at', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_target_without_the_bundle_tables_skips_with_a_note(): void
    {
        Schema::drop('hiring_reports');
        Schema::drop('daily_report_settings');

        $report = $this->migrate();

        $this->assertSame(3, $report->count('hiring_reports', 'ignorees'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'hiring_reports : table absente de la cible')));
        $this->assertSame(2, $report->count('daily_report_settings', 'ignorees'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'daily_report_settings : table absente de la cible')));
        $this->assertSame(2, DB::table('resignation_reports')->count());
        $this->assertSame(3, DB::table('daily_reports')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, $this->total());
        $this->assertSame(2, $report->count('hiring_reports'));
        $this->assertSame(3, $report->count('daily_reports'));
        $this->assertSame(1, $report->count('daily_report_settings'));
    }

    public function test_the_import_is_atomic_when_the_target_is_not_empty(): void
    {
        $this->migrate();
        try {
            $this->migrate();
            $this->fail('La cible non vide aurait dû être refusée.');
        } catch (MigrationException) {
            $this->assertSame(3, DB::table('daily_reports')->count());
        }
    }
}
