<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KintaiLegacy\Importer;
use KintaiLegacy\Report;
use KintaiLegacy\Source;
use KintaiLegacy\Target;
use KintaiLegacy\Tests\Support\SyntheticSource;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/** Tranche 2g (annuaire) : colonnes facultatives de `users` vers `team_directory_profiles`. */
class LegacyDirectoryMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBundle('team-directory');
        $this->legacy = new SyntheticSource;
        $this->storage = sys_get_temp_dir().'/kintai-target-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0777, true);

        $pdo = $this->legacy->pdo;
        $pdo->exec('ALTER TABLE "users" ADD COLUMN "languages_spoken" TEXT');
        $pdo->exec('ALTER TABLE "users" ADD COLUMN "hobbies" TEXT');
        $pdo->exec('ALTER TABLE "users" ADD COLUMN "show_in_directory" TEXT');
        $pdo->exec('UPDATE "users" SET "bio" = NULL');
        $update = $pdo->prepare('UPDATE "users" SET "bio" = ?, "skills" = ?, "languages_spoken" = ?, "hobbies" = ?, "show_in_directory" = ? WHERE "id" = ?');
        $update->execute(['Bonjour', 'PHP, Laravel', 'fr, ja', 'Vélo', 1, 1]);
        $update->execute([null, '', null, null, 0, 2]); // masqué, sans autre champ
        $update->execute([null, null, null, null, null, 3]); // rien : visible, pas de ligne
        $update->execute([null, str_repeat('s', 501), null, null, 1, 4]); // seul champ trop long : ignoré
        $update->execute(['Texte', null, null, str_repeat('h', 501), null, 5]); // un champ repris, un trop long
    }

    protected function tearDown(): void
    {
        $this->legacy->cleanup();
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function migrate(?Source $source = null, bool $dryRun = false): Report
    {
        $target = new Target(DB::connection()->getPdo(), 'sqlite', $this->storage);

        return (new Importer($source ?? $this->legacy->source(), $target, 'Asia/Tokyo', $dryRun))->run();
    }

    private function hasWarning(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->warnings, fn (string $n): bool => str_contains($n, $needle));
    }

    public function test_profiles_are_created_only_for_filled_or_hidden_employees(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 5], DB::table('team_directory_profiles')->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $one = DB::table('team_directory_profiles')->where('user_id', 1)->first();
        $this->assertSame('Bonjour', $one->bio);
        $this->assertSame('PHP, Laravel', $one->skills);
        $this->assertSame('fr, ja', $one->languages_spoken);
        $this->assertSame('Vélo', $one->hobbies);
        $this->assertSame(1, (int) $one->show_in_directory);
        $this->assertSame('2026-03-01 00:00:00', $one->created_at);

        $two = DB::table('team_directory_profiles')->where('user_id', 2)->first();
        $this->assertSame(0, (int) $two->show_in_directory);
        $this->assertNull($two->bio);
        $this->assertNull($two->skills);

        // Valeur trop longue : ignorée, jamais tronquée ; le reste de la ligne est repris, visible par défaut.
        $five = DB::table('team_directory_profiles')->where('user_id', 5)->first();
        $this->assertSame('Texte', $five->bio);
        $this->assertNull($five->hobbies);
        $this->assertSame(1, (int) $five->show_in_directory);
        $this->assertTrue($this->hasWarning($report, "champ « hobbies » de l'utilisateur 5 trop long"));

        $this->assertSame(4, $report->count('team_directory_profiles', 'lues'));
        $this->assertSame(3, $report->count('team_directory_profiles'));
        $this->assertSame(1, $report->count('team_directory_profiles', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, "champ « skills » de l'utilisateur 4 trop long"));
        $this->assertTrue($this->hasWarning($report, "profil de l'utilisateur 4 sans aucun champ repris"));
    }

    public function test_source_without_the_directory_columns_is_not_refused_and_imports_nothing(): void
    {
        foreach (['bio', 'skills', 'languages_spoken', 'hobbies', 'show_in_directory'] as $column) {
            $this->legacy->pdo->exec('ALTER TABLE "users" DROP COLUMN "'.$column.'"');
        }

        $report = $this->migrate();

        $this->assertSame(0, DB::table('team_directory_profiles')->count());
        $this->assertArrayNotHasKey('team_directory_profiles', $report->tables);
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_a_source_with_only_some_columns_uses_the_default_visibility(): void
    {
        foreach (['skills', 'languages_spoken', 'hobbies', 'show_in_directory'] as $column) {
            $this->legacy->pdo->exec('ALTER TABLE "users" DROP COLUMN "'.$column.'"');
        }

        $report = $this->migrate();

        $this->assertSame([1, 5], DB::table('team_directory_profiles')->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, (int) DB::table('team_directory_profiles')->where('user_id', 5)->value('show_in_directory'));
        $this->assertSame(2, $report->count('team_directory_profiles'));
    }

    public function test_target_without_the_bundle_table_skips_with_a_note(): void
    {
        Schema::drop('team_directory_profiles');

        $report = $this->migrate();

        $this->assertSame(4, $report->count('team_directory_profiles', 'ignorees'));
        $this->assertSame(0, $report->count('team_directory_profiles'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'team_directory_profiles : table absente de la cible')));
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, DB::table('team_directory_profiles')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(3, $report->count('team_directory_profiles'));
    }
}
