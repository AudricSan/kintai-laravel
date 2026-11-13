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
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/** Tranche 2d (photos de magasin) : soumissions, images et copie des fichiers. */
class LegacyStorePhotosMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBundle('store-photos');
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

    private function seedLegacy(): void
    {
        $directory = $this->legacy->uploads.'/store_photos';
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/a.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $jpeg = imagecreatetruecolor(2, 3);
        imagejpeg($jpeg, $directory.'/b.jpg');
        file_put_contents($directory.'/c.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
        file_put_contents($directory.'/d.png', 'ce n\'est pas une image');

        $at = '2026-03-02 09:00:00'; // heure locale (Asia/Tokyo) : 00:00:00 en UTC
        $this->insert('store_photo_submissions', ['id' => 1, 'store_id' => 1, 'week_label' => '2026-W10', 'notes' => 'Semaine 10', 'created_by' => 2, 'created_at' => $at, 'updated_at' => $at]);
        $this->insert('store_photo_submissions', ['id' => 2, 'store_id' => 1, 'deleted_at' => '2026-03-03 09:00:00', 'created_by' => 2]);
        $this->insert('store_photo_submissions', ['id' => 3, 'store_id' => 99, 'created_by' => 2]);
        $this->insert('store_photo_submissions', ['id' => 4, 'store_id' => 2, 'created_by' => 2]);
        $this->insert('store_photo_submissions', ['id' => 5, 'store_id' => 1, 'created_by' => 99, 'notes' => '']);
        $this->insert('store_photo_submissions', ['id' => 6, 'store_id' => 1]);

        $image = fn (int $id, int $submission, ?string $path, int $order = 0): array => ['id' => $id, 'submission_id' => $submission, 'filename' => $path === null ? null : basename($path),
            'filepath' => $path, 'sort_order' => $order, 'created_at' => '2026-03-02 09:00:00'];
        $this->insert('store_photo_images', $image(1, 1, 'store_photos/a.png', 1));
        $this->insert('store_photo_images', $image(2, 1, 'store_photos/b.jpg', 0));
        $this->insert('store_photo_images', $image(3, 1, 'store_photos/missing.png')); // fichier absent
        $this->insert('store_photo_images', $image(4, 2, 'store_photos/a.png')); // soumission supprimée
        $this->insert('store_photo_images', $image(5, 3, 'store_photos/a.png')); // magasin absent
        $this->insert('store_photo_images', $image(6, 4, 'store_photos/c.gif')); // type non accepté
        $this->insert('store_photo_images', $image(7, 4, 'store_photos/d.png')); // pas une image
        $this->insert('store_photo_images', $image(8, 5, 'uploads/store_photos/a.png')); // préfixe uploads/ toléré
        $this->insert('store_photo_images', $image(9, 6, '../secret.png')); // hors du dossier
        $this->insert('store_photo_images', $image(10, 6, '/etc/passwd')); // chemin absolu
        $this->insert('store_photo_images', $image(11, 77, 'store_photos/a.png')); // soumission inconnue
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

    private function hasWarning(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->warnings, fn (string $n): bool => str_contains($n, $needle));
    }

    /** @return list<string> */
    private function copied(): array
    {
        return array_map('basename', glob($this->storage.'/store-photos/*') ?: []);
    }

    public function test_submissions_keep_their_ids_and_only_those_with_an_imported_image(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 5], $this->ids('store_photo_submissions'));
        $one = DB::table('store_photo_submissions')->find(1);
        $this->assertSame(1, (int) $one->store_id);
        $this->assertSame(2, (int) $one->created_by);
        $this->assertSame('Semaine 10', $one->notes);
        $this->assertNull($one->archived_at);
        $this->assertSame('2026-03-02 00:00:00', $one->created_at);
        $five = DB::table('store_photo_submissions')->find(5);
        $this->assertNull($five->created_by);
        $this->assertNull($five->notes);

        $this->assertSame(6, $report->count('store_photo_submissions', 'lues'));
        $this->assertSame(2, $report->count('store_photo_submissions'));
        $this->assertSame(4, $report->count('store_photo_submissions', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'soumission 2 supprimée'));
        $this->assertTrue($this->hasWarning($report, 'soumission 4 sans aucune image reprise'));
        $this->assertTrue($this->hasWarning($report, 'soumission 6 sans aucune image reprise'));
    }

    public function test_images_are_copied_with_a_generated_name_and_the_rest_is_refused_with_a_warning(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 8], $this->ids('store_photo_images'));
        $copied = $this->copied();
        $this->assertCount(3, $copied);
        foreach (DB::table('store_photo_images')->get() as $image) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.(jpg|png)$/', $image->path);
            $this->assertContains($image->path, $copied);
            $this->assertSame(filesize($this->storage.'/store-photos/'.$image->path), (int) $image->filesize);
        }
        $png = DB::table('store_photo_images')->find(1);
        $this->assertSame(1, (int) $png->submission_id);
        $this->assertSame('a.png', $png->filename);
        $this->assertStringEndsWith('.png', $png->path);
        $this->assertSame('image/png', $png->mime_type);
        $this->assertSame([1, 1, 1], [(int) $png->width, (int) $png->height, (int) $png->sort_order]);
        $jpeg = DB::table('store_photo_images')->find(2);
        $this->assertStringEndsWith('.jpg', $jpeg->path);
        $this->assertSame('image/jpeg', $jpeg->mime_type);
        $this->assertSame([2, 3, 0], [(int) $jpeg->width, (int) $jpeg->height, (int) $jpeg->sort_order]);
        $this->assertSame(5, (int) DB::table('store_photo_images')->find(8)->submission_id);

        $this->assertSame(11, $report->count('store_photo_images', 'lues'));
        $this->assertSame(3, $report->count('store_photo_images'));
        $this->assertSame(8, $report->count('store_photo_images', 'ignorees'));
        foreach (['fichier absent', 'seuls JPEG et PNG sont repris', 'ce n\'est pas une image acceptable', 'chemin refusé'] as $why) {
            $this->assertTrue($this->hasWarning($report, $why), $why);
        }
    }

    public function test_the_source_files_are_never_modified(): void
    {
        $before = md5_file($this->legacy->uploads.'/store_photos/a.png');

        $this->migrate();

        $this->assertSame($before, md5_file($this->legacy->uploads.'/store_photos/a.png'));
        $this->assertFileExists($this->legacy->uploads.'/store_photos/b.jpg');
    }

    public function test_source_without_the_photo_tables_skips_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, DB::table('store_photo_submissions')->count());
        foreach (['store_photo_submissions', 'store_photo_images'] as $table) {
            $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_starts_with($n, $table.' : absente de la source')), $table);
            $this->assertArrayNotHasKey($table, $report->tables);
        }
        $this->assertSame([], $this->copied());
    }

    public function test_photo_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "store_photo_images" DROP COLUMN "filepath"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('store_photo_images.filepath', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_target_without_the_bundle_tables_skips_with_a_note_and_copies_nothing(): void
    {
        Schema::drop('store_photo_images');
        Schema::drop('store_photo_submissions');

        $report = $this->migrate();

        $this->assertSame(6, $report->count('store_photo_submissions', 'ignorees'));
        $this->assertSame(11, $report->count('store_photo_images', 'ignorees'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'store_photo_submissions : table absente de la cible')));
        $this->assertSame([], $this->copied());
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_dry_run_writes_and_copies_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, DB::table('store_photo_submissions')->count());
        $this->assertSame(0, DB::table('store_photo_images')->count());
        $this->assertSame([], $this->copied());
        $this->assertSame(2, $report->count('store_photo_submissions'));
        $this->assertSame(3, $report->count('store_photo_images'));
    }

    public function test_a_refused_target_leaves_no_copied_file(): void
    {
        // Un utilisateur déjà présent fait refuser la cible avant toute copie : aucun fichier ne doit rester.
        DB::table('users')->insert(['id' => 500, 'name' => 'x', 'first_name' => 'x', 'last_name' => 'x', 'email' => 'x@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->migrate();
            $this->fail('La cible non vide aurait dû être refusée.');
        } catch (MigrationException) {
            $this->assertSame([], $this->copied());
            $this->assertSame(0, DB::table('store_photo_images')->count());
        }
    }
}
