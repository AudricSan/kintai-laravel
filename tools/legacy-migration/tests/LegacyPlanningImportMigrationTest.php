<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KintaiLegacy\Importer;
use KintaiLegacy\MigrationException;
use KintaiLegacy\Report;
use KintaiLegacy\Source;
use KintaiLegacy\Target;
use KintaiLegacy\Tests\Support\SyntheticSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/** Tranche 2f (import de planning) : réglages de grille par magasin et alias de noms. */
class LegacyPlanningImportMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBundle('planning-import');
        $this->legacy = new SyntheticSource;
        $this->storage = sys_get_temp_dir().'/kintai-target-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0777, true);
        $this->seedLegacy();
    }

    protected function tearDown(): void
    {
        $this->legacy->cleanup();
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->legacy->pdo->prepare('INSERT INTO "'.$table.'" ('.implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns)).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')')
            ->execute(array_values($row));
    }

    /** @param  array<string, mixed>  $override */
    private function setting(int $id, int $store, array $override = []): void
    {
        $this->insert('store_import_settings', $override + ['id' => $id, 'store_id' => $store, 'col_start' => 4, 'col_end' => 52, 'base_hour' => 6, 'minutes_per_col' => 30,
            'block_size' => 9, 'shift_rows' => 7, 'sheet_filter_pattern' => '^\d{4}$', 'auto_pause_after_minutes' => 0, 'auto_pause_minutes' => 30]);
    }

    private function seedLegacy(): void
    {
        $at = '2026-03-02 09:00:00'; // heure locale (Asia/Tokyo) : 00:00:00 en UTC

        $this->setting(1, 1, ['col_start' => 5, 'col_end' => 60, 'base_hour' => 7, 'minutes_per_col' => 15, 'block_size' => 8, 'shift_rows' => 6,
            'sheet_filter_pattern' => '^20\d{2}$', 'auto_pause_after_minutes' => 360, 'auto_pause_minutes' => 45, 'created_at' => $at, 'updated_at' => $at]);
        $this->setting(2, 2, ['sheet_filter_pattern' => '^~\d+$', 'base_hour' => 0]);
        $this->setting(3, 99);
        $this->setting(4, 1);

        $this->insert('import_aliases', ['id' => 1, 'store_id' => 1, 'user_id' => 3, 'staff_name' => 'Yamada', 'created_at' => $at]);
        $this->insert('import_aliases', ['id' => 2, 'store_id' => 1, 'user_id' => 2, 'staff_name' => 'tanaka']);
        $this->insert('import_aliases', ['id' => 3, 'store_id' => 1, 'user_id' => 2, 'staff_name' => 'YAMADA']);
        $this->insert('import_aliases', ['id' => 4, 'store_id' => 2, 'user_id' => 3, 'staff_name' => 'Yamada']);
        $this->insert('import_aliases', ['id' => 5, 'store_id' => 99, 'user_id' => 3, 'staff_name' => 'orphelin']);
        $this->insert('import_aliases', ['id' => 6, 'store_id' => 1, 'user_id' => 99, 'staff_name' => 'inconnu']);
        $this->insert('import_aliases', ['id' => 7, 'store_id' => 1, 'user_id' => 3, 'staff_name' => '  ']);
        $this->insert('import_aliases', ['id' => 8, 'store_id' => 1, 'user_id' => 3, 'staff_name' => str_repeat('a', 256)]);
        $this->insert('import_aliases', ['id' => 9, 'store_id' => 1, 'user_id' => 2, 'staff_name' => 'ÉVE']);
    }

    private function migrate(?Source $source = null, bool $dryRun = false): Report
    {
        $target = new Target(DB::connection()->getPdo(), 'sqlite', $this->storage, (string) config('app.key'));

        return (new Importer($source ?? $this->legacy->source(), $target, 'Asia/Tokyo', $dryRun))->run();
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        return DB::table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function hasWarning(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->warnings, fn (string $n): bool => str_contains($n, $needle));
    }

    public function test_settings_are_migrated_per_store_with_their_values(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2], DB::table('store_import_settings')->orderBy('store_id')->pluck('store_id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('store_import_settings')->where('store_id', 1)->first();
        $this->assertSame([5, 60, 7, 15, 8, 6, 360, 45], [(int) $one->col_start, (int) $one->col_end, (int) $one->base_hour, (int) $one->minutes_per_col,
            (int) $one->block_size, (int) $one->shift_rows, (int) $one->auto_pause_after_minutes, (int) $one->auto_pause_minutes]);
        $this->assertSame('^20\d{2}$', $one->sheet_filter_pattern);
        $this->assertSame('2026-03-02 00:00:00', $one->created_at);
        $this->assertSame('2026-03-02 00:00:00', $one->updated_at);
        $two = DB::table('store_import_settings')->where('store_id', 2)->first();
        $this->assertSame(0, (int) $two->base_hour); // 0 est une valeur valable
        $this->assertSame('^~\d+$', $two->sheet_filter_pattern);

        $this->assertSame(4, $report->count('store_import_settings', 'lues'));
        $this->assertSame(2, $report->count('store_import_settings'));
        $this->assertSame(2, $report->count('store_import_settings', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'réglage 4 en double'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidSettings(): array
    {
        return [
            'entier négatif' => [['col_start' => -1], 'col_start'],
            'hors plage' => [['col_end' => 65536], 'col_end'],
            'non numérique' => [['block_size' => 'neuf'], 'block_size'],
            'décimal' => [['shift_rows' => '7.5'], 'shift_rows'],
            'vide' => [['auto_pause_minutes' => ''], 'auto_pause_minutes'],
            'motif invalide' => [['sheet_filter_pattern' => '(non fermé'], 'expression régulière'],
            'motif vide' => [['sheet_filter_pattern' => ''], 'motif de feuille'],
            'motif trop long' => [['sheet_filter_pattern' => str_repeat('a', 256)], 'motif de feuille'],
        ];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('invalidSettings')]
    public function test_an_invalid_setting_is_not_migrated_and_nothing_is_invented(array $override, string $needle): void
    {
        $this->legacy->pdo->exec('DELETE FROM "store_import_settings"');
        $this->setting(1, 1, $override);

        $report = $this->migrate();

        $this->assertSame(0, DB::table('store_import_settings')->count());
        $this->assertSame(1, $report->count('store_import_settings', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'réglage du magasin 1'));
        $this->assertTrue($this->hasWarning($report, $needle));
        $this->assertSame(5, DB::table('users')->count()); // le reste de la migration a eu lieu
    }

    public function test_aliases_keep_their_ids_names_are_lowercased_and_duplicates_keep_the_smallest_id(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 4, 9], $this->ids('import_aliases'));
        $one = DB::table('import_aliases')->find(1);
        $this->assertSame('yamada', $one->staff_name);
        $this->assertSame(1, (int) $one->store_id);
        $this->assertSame(3, (int) $one->user_id);
        $this->assertSame('2026-03-02 00:00:00', $one->created_at);
        $this->assertSame('2026-03-02 00:00:00', $one->updated_at);
        $this->assertSame('tanaka', DB::table('import_aliases')->find(2)->staff_name);
        $this->assertSame('yamada', DB::table('import_aliases')->find(4)->staff_name); // même nom, autre magasin
        $this->assertSame('éve', DB::table('import_aliases')->find(9)->staff_name);

        $this->assertSame(9, $report->count('import_aliases', 'lues'));
        $this->assertSame(4, $report->count('import_aliases'));
        $this->assertSame(5, $report->count('import_aliases', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'alias 3 en double après mise en minuscules'));
        $this->assertTrue($this->hasWarning($report, 'alias 7 au nom vide ou de plus de 255 caractères'));
        $this->assertTrue($this->hasWarning($report, 'alias 8 au nom vide ou de plus de 255 caractères'));
        $this->assertTrue($this->hasWarning($report, '3 nom(s) d\'alias mis en minuscules'));
    }

    public function test_old_alias_name_column_is_read(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "import_aliases" RENAME COLUMN "staff_name" TO "alias_name"');

        $this->migrate();

        $this->assertSame([1, 2, 4, 9], $this->ids('import_aliases'));
        $this->assertSame('yamada', DB::table('import_aliases')->find(1)->staff_name);
    }

    public function test_staff_name_is_preferred_when_both_columns_exist(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "import_aliases" ADD COLUMN "alias_name" TEXT');
        $this->legacy->pdo->exec('UPDATE "import_aliases" SET "alias_name" = \'ancien\' WHERE "id" IN (1, 2)');
        $this->legacy->pdo->exec('UPDATE "import_aliases" SET "staff_name" = NULL, "alias_name" = \'Repli\' WHERE "id" = 9');

        $this->migrate();

        $this->assertSame('yamada', DB::table('import_aliases')->find(1)->staff_name);
        $this->assertSame('tanaka', DB::table('import_aliases')->find(2)->staff_name);
        $this->assertSame('repli', DB::table('import_aliases')->find(9)->staff_name);
    }

    public function test_alias_table_without_any_name_column_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "import_aliases" DROP COLUMN "staff_name"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('import_aliases.staff_name ou alias_name', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_settings_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "store_import_settings" DROP COLUMN "block_size"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('store_import_settings.block_size', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_source_without_the_planning_import_tables_skips_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, DB::table('store_import_settings')->count() + DB::table('import_aliases')->count());
        foreach (['store_import_settings', 'import_aliases'] as $table) {
            $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_starts_with($n, $table.' : absente de la source')), $table);
        }
    }

    public function test_target_without_the_bundle_tables_skips_with_a_note(): void
    {
        Schema::drop('import_aliases');
        Schema::drop('store_import_settings');

        $report = $this->migrate();

        $this->assertSame(4, $report->count('store_import_settings', 'ignorees'));
        $this->assertSame(9, $report->count('import_aliases', 'ignorees'));
        $this->assertSame(0, $report->count('import_aliases'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'store_import_settings : table absente de la cible')));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'import_aliases : table absente de la cible')));
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, DB::table('store_import_settings')->count() + DB::table('import_aliases')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(2, $report->count('store_import_settings'));
        $this->assertSame(4, $report->count('import_aliases'));
    }
}
