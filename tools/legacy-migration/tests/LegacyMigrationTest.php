<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use KintaiLegacy\Importer;
use KintaiLegacy\MigrationException;
use KintaiLegacy\Report;
use KintaiLegacy\Source;
use KintaiLegacy\Target;
use KintaiLegacy\Tests\Support\SyntheticSource;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/**
 * L'outil lui-même n'utilise aucune classe de l'application ; ce test, lui, s'appuie sur le schéma migré, le seeder et
 * le pilote de hachage de l'application pour prouver que le résultat est exploitable.
 */
class LegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacy = new SyntheticSource;
        $this->storage = sys_get_temp_dir().'/kintai-target-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->legacy->cleanup();
        $this->removeDirectory($this->storage);
        parent::tearDown();
    }

    private function target(): Target
    {
        return new Target(DB::connection()->getPdo(), 'sqlite', $this->storage);
    }

    private function migrate(?Source $source = null, bool $dryRun = false): Report
    {
        return (new Importer($source ?? $this->legacy->source(), $this->target(), 'Asia/Tokyo', $dryRun))->run();
    }

    public function test_synthetic_source_is_imported_into_the_new_schema(): void
    {
        $report = $this->migrate();

        // Utilisateurs : identifiants conservés, soft-delete, profil RH à part, couleur invalide remplacée.
        $this->assertSame(5, User::withTrashed()->count());
        $owner = User::findOrFail(1);
        $this->assertSame('Ada Owner', $owner->name);
        $this->assertSame('fr', $owner->locale);
        $this->assertSame('#112233', $owner->color);
        $this->assertTrue($owner->share_email);
        $this->assertSame('オーナー', $owner->furigana_last_name);
        $this->assertSame('1990-05-17', $owner->profile->birth_date->toDateString());
        $this->assertSame('0100000001', $owner->profile->phone);
        $this->assertSame('#3B82F6', User::findOrFail(2)->color);
        $this->assertNull(User::findOrFail(2)->locale);
        $this->assertSame('E003', User::findOrFail(3)->employee_code);
        $this->assertSame('Bob Broken', User::findOrFail(4)->name);
        $this->assertFalse(User::findOrFail(4)->is_active);
        $this->assertTrue(User::withTrashed()->findOrFail(5)->trashed());
        $this->assertNull(User::findOrFail(3)->profile);
        // L'ancien « last_login_at », « bio »... n'existent plus : rien à vérifier côté cible.

        // Magasins : -1 devient null, 0 (dimanche) devient 7, devise en majuscules.
        $a = Store::findOrFail(1);
        $this->assertNull($a->low_staff_hour_start);
        $this->assertSame('JPY', $a->currency);
        $this->assertSame('Asia/Tokyo', $a->timezone);
        $b = Store::findOrFail(2);
        $this->assertSame(14, $b->low_staff_hour_start);
        $this->assertSame(7, $b->week_start_day);

        // Affectations : l'orpheline (utilisateur 99) est ignorée, la date illisible devient null.
        $this->assertSame(3, DB::table('store_user')->count());
        $this->assertSame('M1', DB::table('store_user')->where(['store_id' => 1, 'user_id' => 2])->value('staff_code'));
        $this->assertNull(DB::table('store_user')->where(['store_id' => 1, 'user_id' => 3])->value('hire_date'));
        $this->assertSame(0, (int) DB::table('store_user')->where(['store_id' => 2, 'user_id' => 3])->value('is_active'));
        $this->assertSame(0, (int) DB::table('store_features')->where(['store_id' => 1, 'feature' => 'timeclock'])->value('enabled'));

        // Types de quart et taux personnels.
        $this->assertSame(1, DB::table('shift_types')->count());
        $this->assertSame('09:00:00', DB::table('shift_types')->value('starts_at'));
        $this->assertEquals(1200.5, DB::table('shift_types')->value('hourly_rate'));
        $this->assertSame(1, DB::table('shift_type_store')->count());
        $this->assertEquals(1500, DB::table('shift_type_user')->value('hourly_rate'));

        // Quarts : heures locales (Asia/Tokyo) converties en UTC, minuit traversé, ouvert = sans employé, supprimé conservé.
        $this->assertSame(4, DB::table('shifts')->count());
        $one = DB::table('shifts')->find(1);
        $this->assertSame('2026-03-02 00:00:00', $one->starts_at);
        $this->assertSame('2026-03-02 08:00:00', $one->ends_at);
        $this->assertSame('2026-03-02', $one->shift_date);
        $this->assertSame(60, (int) $one->pause_minutes);
        $this->assertSame(2, (int) $one->ical_sequence);
        $this->assertSame('Caisse', $one->notes);
        $this->assertSame(2, (int) $one->created_by);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $one->ical_uid);
        $two = DB::table('shifts')->find(2);
        $this->assertSame('2026-03-03 13:00:00', $two->starts_at);
        $this->assertSame('2026-03-03 21:00:00', $two->ends_at);
        $open = DB::table('shifts')->find(3);
        $this->assertNull($open->user_id);
        $this->assertSame('À prendre', $open->notes);
        $this->assertNotNull(DB::table('shifts')->find(4)->deleted_at);
        $this->assertSame(4, DB::table('shifts')->distinct()->count('ical_uid'));

        // Disponibilités : le jour 0 devient 7.
        $this->assertSame([7, 3], DB::table('availabilities')->orderBy('id')->pluck('day_of_week')->map(fn ($d) => (int) $d)->all());

        // Réglages : seuls les réglages du Core sont repris, le fuseau de l'instance est posé.
        $settings = DB::table('app_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
        $this->assertSame('Mon sous-titre', $settings['organization.subtitle']);
        $this->assertSame(7, $settings['backup.max_keep']);
        $this->assertFalse($settings['backup.auto_enabled']);
        $this->assertSame('Asia/Tokyo', $settings['instance.timezone']);
        $this->assertCount(4, $settings);

        // Jetons iCal : non repris (l'ancien jeton en clair n'est pas conservé).
        $this->assertSame(0, DB::table('ical_tokens')->count());
        $this->assertSame(2, $report->count('ical_tokens', 'ignorees'));

        // Rapport.
        $this->assertSame(5, $report->count('users'));
        $this->assertSame(4, $report->count('shifts'));
        $this->assertSame(6, $report->count('shifts', 'lues'));
        $this->assertSame(2, $report->count('shifts', 'ignorees'));
        $this->assertStringContainsString('avertissement', $report->render());
    }

    public function test_bcrypt_hashes_of_the_old_version_log_in_and_weak_ones_must_change(): void
    {
        $this->migrate();

        $owner = User::findOrFail(1);
        $this->assertTrue(Hash::check(SyntheticSource::PASSWORD, $owner->password));
        $this->assertFalse(Hash::check('mauvais', $owner->password));
        $this->assertFalse($owner->must_change_password);
        $this->assertFalse(User::findOrFail(3)->must_change_password);

        // Mot de passe par défaut de l'ancienne version (0000) : rappel de changement.
        $this->assertTrue(Hash::check('0000', User::findOrFail(2)->password));
        $this->assertTrue(User::findOrFail(2)->must_change_password);

        // Empreinte illisible : mot de passe inutilisable et rappel, avec avertissement.
        $broken = User::findOrFail(4);
        $this->assertTrue($broken->must_change_password);
        $this->assertFalse(Hash::check('not-a-hash', $broken->password));

        // Vraie connexion avec l'ancien mot de passe.
        $this->post(route('login.store'), ['login' => 'owner@example.test', 'password' => SyntheticSource::PASSWORD]);
        $this->assertAuthenticatedAs($owner);
    }

    public function test_roles_scopes_and_permissions_are_mapped(): void
    {
        $this->seed(RoleSeeder::class);
        $this->migrate();

        $roleId = fn (string $slug): int => (int) DB::table('roles')->where('slug', $slug)->value('id');
        $this->assertSame(4, DB::table('roles')->count()); // owner, manager, employee du seeder réutilisés + lead

        // Assignations : global = store_id null, magasin = store_id ; portée inconnue, magasin absent ignorés.
        $this->assertSame(4, DB::table('role_user')->count());
        $this->assertNull(DB::table('role_user')->where(['user_id' => 1, 'role_id' => $roleId('owner')])->first()->store_id);
        $this->assertSame(1, (int) DB::table('role_user')->where(['user_id' => 2, 'role_id' => $roleId('manager')])->value('store_id'));
        $this->assertSame(1, (int) DB::table('role_user')->where(['user_id' => 3, 'role_id' => $roleId('employee')])->value('store_id'));
        $this->assertNull(DB::table('role_user')->where(['user_id' => 3, 'role_id' => $roleId('lead')])->first()->store_id);

        // Permissions : scope global -> all_stores, clé de bundle non reprise, permissions du seeder remplacées.
        $manager = DB::table('role_permissions')->where('role_id', $roleId('manager'))->pluck('all_stores', 'permission')->all();
        $this->assertEquals(['employees.view' => 0, 'shifts.view' => 1], $manager);
        $this->assertEquals(['stores.view' => 1], DB::table('role_permissions')->where('role_id', $roleId('lead'))->pluck('all_stores', 'permission')->all());
        $lead = DB::table('roles')->where('slug', 'lead')->first();
        $this->assertSame('Chef de rayon', $lead->name);
        $this->assertSame('#0A0B0C', $lead->color);
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $roleId('owner'))->count());

        // Le Gate de l'application accorde bien ce que la source accordait.
        $store = Store::findOrFail(1);
        $this->assertTrue(User::findOrFail(2)->can('employees.view', $store));
        $this->assertFalse(User::findOrFail(2)->can('employees.view', Store::findOrFail(2)));
        $this->assertFalse(User::findOrFail(2)->can('employees.update', $store));
        $this->assertTrue(User::findOrFail(2)->can('shifts.view', Store::findOrFail(2)));
    }

    public function test_roles_are_created_when_the_target_has_none(): void
    {
        $this->migrate();

        $this->assertEqualsCanonicalizing(['owner', 'manager', 'employee', 'lead'], DB::table('roles')->pluck('slug')->all());
        $this->assertSame(1, (int) DB::table('roles')->where('slug', 'owner')->value('is_system'));
        $this->assertSame(1, (int) DB::table('roles')->where('slug', 'manager')->value('is_manager'));
        $this->assertSame(2, DB::table('role_permissions')->where('role_id', DB::table('roles')->where('slug', 'manager')->value('id'))->count());
    }

    public function test_a_non_empty_target_is_refused_and_left_untouched(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        try {
            $this->migrate();
            $this->fail('La cible non vide aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Cible non vide', $e->getMessage());
        }

        $this->assertSame(1, User::count());
        $this->assertSame(0, DB::table('stores')->count());
        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame([], glob($this->storage.'/avatars/*') ?: []);
    }

    public function test_a_second_run_is_refused_instead_of_merged(): void
    {
        $this->migrate();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Cible non vide');
        $this->migrate();
    }

    public function test_a_source_with_an_unknown_schema_is_refused(): void
    {
        $file = $this->legacy->directory.'/old.sqlite';
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT)');
        $pdo->exec('CREATE TABLE stores (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo = null;

        try {
            $this->migrate(Source::sqlite($file));
            $this->fail('Le schéma inconnu aurait dû être refusé.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Schéma source non pris en charge', $e->getMessage());
            $this->assertStringContainsString('table store_user', $e->getMessage());
        }
        $this->assertSame(0, User::count());
    }

    public function test_a_missing_column_makes_the_source_unsupported(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE role_permissions DROP COLUMN scope');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('colonne role_permissions.scope');
        $this->migrate();
    }

    public function test_a_file_that_is_not_sqlite_is_refused(): void
    {
        $file = $this->legacy->directory.'/fake.sqlite';
        file_put_contents($file, 'ceci n\'est pas une base de données');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("n'est pas une base SQLite");
        Source::sqlite($file);
    }

    public function test_the_source_is_never_modified(): void
    {
        $before = [sha1_file($this->legacy->database), filemtime($this->legacy->database), scandir($this->legacy->directory.'/storage/uploads/avatars')];

        $source = $this->legacy->source();
        $this->migrate($source);

        // La connexion de la source refuse toute écriture.
        try {
            $source->pdo->exec('DELETE FROM users');
            $this->fail('La source devrait être en lecture seule.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(5, (int) $this->legacy->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        clearstatcache();
        $this->assertSame($before, [sha1_file($this->legacy->database), filemtime($this->legacy->database), scandir($this->legacy->directory.'/storage/uploads/avatars')]);
    }

    public function test_avatars_are_copied_and_missing_or_traversing_ones_are_dropped_with_a_warning(): void
    {
        $report = $this->migrate();

        $path = User::findOrFail(1)->avatar_path;
        $this->assertMatchesRegularExpression('#^avatars/user_1_[0-9a-f]{16}\.png$#', $path);
        $this->assertFileEquals($this->legacy->uploads.'/avatars/owner.png', $this->storage.'/'.$path);

        // Chemin avec « .. » : refusé, rien n'est copié.
        $this->assertNull(User::findOrFail(2)->avatar_path);
        // Fichier absent : null.
        $this->assertNull(User::findOrFail(3)->avatar_path);
        $this->assertCount(1, glob($this->storage.'/avatars/*') ?: []);

        $warnings = implode("\n", $report->warnings);
        $this->assertStringContainsString('« ../secret.png »', $warnings);
        $this->assertStringContainsString('chemin refusé', $warnings);
        $this->assertStringContainsString('« gone.png »', $warnings);
        $this->assertStringContainsString('fichier absent', $warnings);
    }

    public function test_a_symbolic_link_or_non_image_in_the_avatar_folder_is_refused(): void
    {
        file_put_contents($this->legacy->uploads.'/avatars/fake.png', 'pas une image');
        $this->legacy->pdo->exec("UPDATE users SET avatar_path = 'fake.png' WHERE id = 3");

        $report = $this->migrate();

        $this->assertNull(User::findOrFail(3)->avatar_path);
        $this->assertStringContainsString("ce n'est pas une image acceptable", implode("\n", $report->warnings));
    }

    public function test_a_failure_rolls_everything_back_and_removes_copied_avatars(): void
    {
        // Même adresse e-mail pour deux comptes : la contrainte d'unicité de la cible échoue après la copie de l'avatar.
        $this->legacy->pdo->exec("UPDATE users SET email = 'owner@example.test' WHERE id = 2");

        try {
            $this->migrate();
            $this->fail("L'échec aurait dû être signalé.");
        } catch (MigrationException $e) {
            $this->assertStringContainsString('la cible est inchangée', $e->getMessage());
        }

        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame(0, DB::table('stores')->count());
        $this->assertSame([], glob($this->storage.'/avatars/*') ?: []);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(null, true);

        $this->assertTrue($report->dryRun);
        $this->assertSame(5, $report->count('users'));
        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSame(0, DB::table('shifts')->count());
        $this->assertSame([], glob($this->storage.'/avatars/*') ?: []);
        $this->assertStringContainsString('simulation', $report->render());
    }

    public function test_the_instance_folder_is_read_without_executing_its_configuration(): void
    {
        $marker = $this->legacy->directory.'/executed';
        file_put_contents($this->legacy->directory.'/config/database.local.php', "<?php\nfile_put_contents('".str_replace('\\', '/', $marker)."', 'x');\nreturn ['driver' => 'sqlite'];\n");

        try {
            Source::fromInstance($this->legacy->directory);
            $this->fail('Une configuration qui exécute du code aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('valeurs littérales', $e->getMessage());
        }
        $this->assertFileDoesNotExist($marker);
    }

    public function test_the_instance_folder_gives_the_database_the_avatars_and_the_timezone(): void
    {
        $source = Source::fromInstance($this->legacy->directory);

        $this->assertSame(realpath($this->legacy->database), $source->file);
        $this->assertSame('Asia/Tokyo', Importer::sourceTimezone($source));
        $this->migrate($source);
        $this->assertNotNull(User::findOrFail(1)->avatar_path);
    }

    public function test_a_sqlite_path_outside_the_instance_folder_is_refused(): void
    {
        $outside = sys_get_temp_dir().'/kintai-outside-'.bin2hex(random_bytes(4)).'.sqlite';
        copy($this->legacy->database, $outside);
        file_put_contents($this->legacy->directory.'/config/database.local.php', "<?php\nreturn ['driver' => 'sqlite', 'connections' => ['sqlite' => ['path' => '".str_replace('\\', '/', $outside)."']]];\n");

        try {
            Source::fromInstance($this->legacy->directory);
            $this->fail('Une base hors du dossier aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('hors du dossier', $e->getMessage());
        } finally {
            @unlink($outside);
        }
    }

    public function test_a_folder_that_is_not_an_old_instance_is_refused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("n'est pas une instance");
        Source::fromInstance($this->storage);
    }

    public function test_target_environment_file_is_parsed_as_data(): void
    {
        $env = Target::parseEnv("# commentaire\nDB_CONNECTION=sqlite\nDB_DATABASE=\"/tmp/a b.sqlite\"\nDB_PASSWORD='p#ss' # note\nexport DB_HOST=127.0.0.1\nVIDE=\n");

        $this->assertSame(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/tmp/a b.sqlite', 'DB_PASSWORD' => 'p#ss', 'DB_HOST' => '127.0.0.1', 'VIDE' => ''], $env);
    }

    public function test_the_tool_does_not_depend_on_the_application(): void
    {
        $files = array_merge(glob(dirname(__DIR__).'/src/*.php') ?: [], [dirname(__DIR__).'/migrate.php', dirname(__DIR__).'/autoload.php']);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/\bApp\\\\|\bIlluminate\\\\|Database\\\\Seeders|Tests\\\\/', (string) file_get_contents($file), basename($file));
        }
    }

    public function test_command_line_help_and_missing_source(): void
    {
        $script = escapeshellarg(dirname(__DIR__).'/migrate.php');
        exec(PHP_BINARY.' '.$script.' --help 2>&1', $output, $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--source=', implode("\n", $output));

        $output = [];
        exec(PHP_BINARY.' '.$script.' 2>&1', $output, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('exactement une source', implode("\n", $output));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                is_dir($path.'/'.$item) ? $this->removeDirectory($path.'/'.$item) : @unlink($path.'/'.$item);
            }
        }
        @rmdir($path);
    }
}
