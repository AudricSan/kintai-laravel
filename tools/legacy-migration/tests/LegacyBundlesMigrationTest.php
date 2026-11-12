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

/** Tranche 2a : reprise des données des bundles timeclock, timeoff, shift-claim et shift-swap. */
class LegacyBundlesMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['timeclock', 'timeoff', 'shift-claim', 'shift-swap'] as $slug) {
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

    public function test_timeclocks_are_converted_to_utc_with_their_ids(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 4, 5], DB::table('timeclocks')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('timeclocks')->find(1);
        $this->assertSame('2026-03-02 00:02:00', $one->clock_in_at);
        $this->assertSame('2026-03-02 08:05:00', $one->clock_out_at);
        $this->assertSame('2026-03-02', $one->shift_date);
        $this->assertSame(1, (int) $one->shift_id);
        $this->assertSame(483, (int) $one->duration_minutes);
        $this->assertSame('10.0.0.2', $one->clock_out_ip);
        $this->assertSame('RAS', $one->notes);
        $this->assertSame('2026-03-02 00:02:00', $one->created_at);

        // Quart non repris : lien retiré, pointage ouvert conservé ; sortie antérieure à l'entrée : repris sans sortie.
        $four = DB::table('timeclocks')->find(4);
        $this->assertNull($four->shift_id);
        $this->assertNull($four->clock_out_at);
        $five = DB::table('timeclocks')->find(5);
        $this->assertNull($five->clock_out_at);
        $this->assertNull($five->duration_minutes);

        $this->assertSame(5, $report->count('timeclocks', 'lues'));
        $this->assertSame(3, $report->count('timeclocks'));
        $this->assertSame(2, $report->count('timeclocks', 'ignorees'));
    }

    public function test_timeoff_requests_are_mapped_and_invalid_ones_skipped(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2], DB::table('timeoff_requests')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('timeoff_requests')->find(1);
        $this->assertSame('2026-04-01', $one->start_date);
        $this->assertSame('2026-04-03', $one->end_date);
        $this->assertSame('vacation', $one->type);
        $this->assertSame('approved', $one->status);
        $this->assertSame('OK', $one->admin_note);
        $this->assertSame(2, (int) $one->processed_by);
        $this->assertSame('2026-03-20 01:00:00', $one->processed_at);
        $two = DB::table('timeoff_requests')->find(2);
        $this->assertSame('other', $two->type);
        $this->assertSame('refused', $two->status);
        $this->assertNull($two->processed_by);

        $this->assertSame(5, $report->count('timeoff_requests', 'lues'));
        $this->assertSame(2, $report->count('timeoff_requests'));
        $this->assertSame(3, $report->count('timeoff_requests', 'ignorees'));
    }

    public function test_shift_claims_skip_duplicates_and_orphans(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 4], DB::table('shift_claims')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('shift_claims')->find(1);
        $this->assertSame(3, (int) $one->shift_id);
        $this->assertSame('pending', $one->status);
        $this->assertSame('Dispo', $one->note);
        $four = DB::table('shift_claims')->find(4);
        $this->assertSame('rejected', $four->status);
        $this->assertSame('2026-03-04 00:00:00', $four->resolved_at);
        $this->assertNull($four->resolved_by);

        $this->assertSame(4, $report->count('shift_claims', 'lues'));
        $this->assertSame(2, $report->count('shift_claims', 'ignorees'));
        $this->assertContains('shift_claims : candidature 2 en double (même quart, même employé), ignorée', $report->warnings);
    }

    public function test_shift_swaps_need_a_target_and_keep_the_shift_sequences(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 4], DB::table('shift_swap_requests')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('shift_swap_requests')->find(1);
        $this->assertSame('accepted', $one->status);
        $this->assertSame(2, (int) $one->requester_shift_sequence); // ical_sequence du quart 1
        $this->assertSame(0, (int) $one->target_shift_sequence);
        $this->assertSame('2026-03-01 01:00:00', $one->accepted_at);
        $this->assertSame(2, (int) $one->approved_by_id);
        $this->assertNull($one->applied_requester_shift_id);
        $this->assertNull($one->applied_target_shift_id);
        $four = DB::table('shift_swap_requests')->find(4);
        $this->assertSame('pending', $four->status); // « accepted » de l'ancienne version : le collègue a dit oui, le gérant n'a pas décidé
        $this->assertNotNull($four->accepted_at);
        $this->assertNull($four->approved_by_id);
        $this->assertSame(2, (int) $four->target_shift_sequence);

        $this->assertSame(4, $report->count('shift_swap_requests', 'lues'));
        $this->assertSame(2, $report->count('shift_swap_requests', 'ignorees'));
        $this->assertTrue((bool) array_filter($report->warnings, fn (string $w): bool => str_contains($w, 'sans collègue ou sans quart cible')));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'accepted_by_id')));
        $this->assertStringContainsString('shift_swap_requests', $report->render());
    }

    public function test_source_without_bundle_tables_skips_the_steps_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, DB::table('timeclocks')->count() + DB::table('timeoff_requests')->count() + DB::table('shift_claims')->count() + DB::table('shift_swap_requests')->count());
        $this->assertSame(4, $report->count('shifts'));
        foreach (array_keys(Source::OPTIONAL) as $table) {
            $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_starts_with($n, $table.' : absente de la source')), $table);
            $this->assertArrayNotHasKey($table, $report->tables);
        }
    }

    public function test_bundle_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "timeoff_requests" DROP COLUMN "admin_note"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('timeoff_requests.admin_note', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_target_without_the_bundle_table_skips_with_a_note(): void
    {
        Schema::drop('shift_claims');

        $report = $this->migrate();

        $this->assertSame(4, $report->count('shift_claims', 'ignorees'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'shift_claims : table absente de la cible')));
        $this->assertSame(2, DB::table('timeoff_requests')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, DB::table('timeclocks')->count() + DB::table('timeoff_requests')->count() + DB::table('shift_claims')->count() + DB::table('shift_swap_requests')->count());
        $this->assertSame(0, DB::table('shifts')->count());
        $this->assertSame(3, $report->count('timeclocks'));
        $this->assertSame(2, $report->count('shift_swap_requests'));
    }
}
