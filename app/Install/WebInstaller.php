<?php

declare(strict_types=1);

namespace App\Install;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Auth\PasswordHasher;
use App\InstalledBundleManifestStore;
use App\Database\DemoDataSeeder;
use App\Models\AppSetting;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\EnvFileWriter;
use App\Services\InstanceLocale;
use App\Services\PublicUrlResolver;
use App\Services\SetupWizard;
use PDO;
use Throwable;

/**
 * Installation d'une instance neuve, appelée par public/install.php : fichier d'environnement, base de données,
 * migrations, compte Owner, verrou. Ou importation d'une instance Kintai existante (import()), par exemple l'ancienne
 * version d'avant Laravel : sa base, ses fichiers et sa configuration sont repris, puis les migrations appliquées.
 *
 * Tout ou rien : chaque écriture enregistre de quoi l'annuler, et un échec à n'importe quelle étape remet l'instance
 * dans l'état d'avant (fichier d'environnement restauré, base SQLite ou MySQL créée par l'installateur supprimée,
 * tables vidées d'une base MySQL existante qui était vide). Une nouvelle tentative repart donc d'une base vierge, au
 * lieu de buter sur le compte Owner déjà créé par la tentative précédente.
 *
 * Une base qui contient déjà des tables est refusée d'emblée : elle peut porter les données d'une instance dont le
 * verrou et la configuration ont disparu, et l'annulation ne doit jamais rien supprimer que l'installateur n'a pas créé.
 */
final class WebInstaller
{
    public const SQLITE_PATH = 'storage/app/database.sqlite';

    /**
     * Contenu de storage/ que l'importation ne reprend pas : caches et vues compilées (refaits par l'application),
     * archives et manifeste de l'ancienne mise à jour (ils décrivent l'ancien code), compteurs de limitation, et la base
     * SQLite et le verrou, écrits à part.
     */
    private const IMPORT_SKIPPED = [
        'cache', 'framework', 'logs/rate-limits', 'app/updates', 'app/update-files-manifest.json',
        'app/database.sqlite', 'app/database.sqlite-wal', 'app/database.sqlite-shm', 'app/database.sqlite-journal',
        'installed.lock',
    ];

    /** @var list<Closure> annulations, jouées en ordre inverse */
    private array $undo = [];

    public function __construct(private readonly string $basePath) {}

    /**
     * @param string|null $publicUrl adresse publique de l'instance (liens des e-mails), déjà normalisée
     * @return array{owner_id:int, demo_accounts: list<array{name:string, email:string, role:string}>}
     * @throws InstallFailure toujours avec une clé de traduction ; le détail est dans storage/logs/error.log
     */
    public function install(InstallInput $in, ?string $publicUrl): array
    {
        $this->undo = [];

        try {
            // En premier : un fichier d'environnement non inscriptible arrête tout avant la moindre écriture en base.
            $this->writeLocale($in->locale);
            $this->writeAppKey();

            $in->driver === 'mysql' ? $this->prepareMysql($in) : $this->prepareSqlite();

            $this->writeDatabaseEnv($this->databaseEnv($in));

            $app = require $this->basePath . '/bootstrap/boot.php';
            $this->migrate($app);

            if ($in->demoData) {
                // Données de démonstration du Core, écrites par les modèles (indépendantes du pilote et du schéma).
                (new DemoDataSeeder())->run();
            }

            $ownerId = $this->createOwner($app, $in);

            // Sert aux liens absolus des e-mails ; modifiable dans /admin/owner-settings, APP_URL prime (PublicUrlResolver).
            if ($publicUrl !== null) {
                AppSetting::putMany([PublicUrlResolver::SETTING_KEY => $publicUrl]);
            }
            // Aucun bundle actif au départ ; l'Owner choisit sa licence puis ses bundles dans /admin/setup.
            SetupWizard::start($app->make(AppSettingsService::class)->updateChannel());

            $this->writeFile('storage/installed.lock', bin2hex(random_bytes(32)));

            return [
                'owner_id'      => $ownerId,
                'demo_accounts' => $in->demoData ? DemoDataSeeder::accounts() : [],
            ];
        } catch (Throwable $e) {
            $this->log($e);
            $this->rollback();
            throw InstallFailure::from($e);
        }
    }

    /**
     * `php artisan migrate` puis `php artisan db:seed` : le schéma du Cœur et des bundles installés, puis les données de
     * départ (rôles, registre officiel, clés du web push).
     *
     * @return int nombre de migrations appliquées
     */
    private function migrate(Application $app): int
    {
        $before = Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0;

        $kernel = $app->make(Kernel::class);
        $kernel->call('migrate', ['--force' => true]);
        $kernel->call('db:seed', ['--force' => true]);

        return DB::table('migrations')->count() - $before;
    }

    /**
     * Une instance importée d'avant les migrations Laravel garde son schéma : son journal de migrations (sans numéro de
     * lot) est remplacé par celui de Laravel, où les migrations de création sont marquées appliquées.
     */
    private function adoptLegacyMigrationLog(Application $app): void
    {
        if (!Schema::hasTable('migrations') || Schema::hasColumn('migrations', 'batch')) {
            return;
        }

        Schema::dropIfExists('migrations');
        Schema::dropIfExists('bundle_migrations');
        $app->make(Kernel::class)->call('migrate:install');
        foreach (glob($this->basePath . '/database/migrations/*.php') as $file) {
            DB::table('migrations')->insert(['migration' => basename($file, '.php'), 'batch' => 1]);
        }
    }

    /**
     * Importe une instance Kintai installée dans un autre dossier du même serveur (ancienne version comprise) : sa base
     * (SQLite copiée, MySQL reprise telle quelle), son .env, storage/ (fichiers envoyés, sauvegardes, bundles installés)
     * et sa configuration ; puis les migrations du Core et des bundles. Le dossier importé n'est jamais modifié.
     *
     * Tout ou rien, comme install() : un échec retire tout ce qui a été copié. Seules les migrations déjà appliquées à
     * une base MySQL (partagée avec l'ancienne instance) ne peuvent pas être annulées.
     *
     * @param string      $locale    langue écrite dans .env si l'instance importée n'en avait pas
     * @param string|null $publicUrl adresse d'où l'on importe : la nouvelle adresse publique de l'instance
     * @return array{migrations:int, users:int, bundles: list<array{slug:string, version:string}>}
     * @throws InstallFailure
     */
    public function import(string $sourceDir, string $locale, ?string $publicUrl = null): array
    {
        $this->undo = [];

        try {
            $source = $this->importSource($sourceDir);
            $database = $this->importDatabaseConfig($source);

            $env = is_file($source . '/.env') ? (string) file_get_contents($source . '/.env') : '';
            if ($env !== '') {
                $this->writeFile('.env', $env);
            }
            if (preg_match('/^' . InstanceLocale::ENV_KEY . '=\S/m', $env) !== 1) {
                $this->writeLocale($locale);
            }
            $this->writeAppKey();

            if ($database['driver'] === 'sqlite') {
                $this->importSqlite($this->sqliteSource($source, $database));
            }
            $this->copyTree($source . '/storage', $this->basePath . '/storage', '');
            $this->writeDatabaseEnv($this->importedDatabaseEnv($database));

            $app = require $this->basePath . '/bootstrap/boot.php';
            $this->adoptLegacyMigrationLog($app);
            $migrations = $this->migrate($app);
            $bundles = [];
            foreach ((new InstalledBundleManifestStore($this->basePath . '/storage/bundles/installed.json'))->all() as $slug => $entry) {
                $bundles[] = ['slug' => (string) $slug, 'version' => (string) ($entry['active_version'] ?? '')];
            }

            $users = User::countActive();
            if ($users === 0) {
                throw new InstallFailure('install_import_error_empty', ['name' => $source]);
            }

            if ($publicUrl !== null) {
                AppSetting::putMany([PublicUrlResolver::SETTING_KEY => $publicUrl]);
            }

            $this->writeFile('storage/installed.lock', bin2hex(random_bytes(32)));

            return ['migrations' => $migrations, 'users' => $users, 'bundles' => $bundles];
        } catch (Throwable $e) {
            $this->log($e);
            $this->rollback();
            throw InstallFailure::from($e);
        }
    }

    // ─── Étapes ───────────────────────────────────────────────────────────────

    /** Clé de chiffrement de l'instance (`php artisan key:generate`), si le fichier d'environnement n'en a pas. */
    private function writeAppKey(): void
    {
        $env = new EnvFileWriter($this->basePath);
        $content = is_file($env->path()) ? (string) file_get_contents($env->path()) : '';
        if (preg_match('/^APP_KEY=\S/m', $content) === 1) {
            return;
        }
        $this->rememberFile($env->path());
        try {
            $env->set('APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
        } catch (\RuntimeException $e) {
            throw new InstallFailure('install_error_not_writable', ['name' => basename($env->path())], $e);
        }
    }

    private function writeLocale(string $locale): void
    {
        if ($locale === '') {
            return;
        }
        $env = new EnvFileWriter($this->basePath);
        $path = $env->path();
        $this->rememberFile($path);
        try {
            $env->set(InstanceLocale::ENV_KEY, $locale);
        } catch (\RuntimeException $e) {
            throw new InstallFailure('install_error_not_writable', ['name' => basename($path)], $e);
        }
    }

    private function prepareSqlite(): void
    {
        $path = $this->basePath . '/' . self::SQLITE_PATH;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new InstallFailure('install_error_not_writable', ['name' => 'storage/app/']);
        }

        if (file_exists($path)) {
            // Fichier laissé par une ancienne installation : une installation neuve le remplace. Rien n'est perdu : un fichier
            // qui contient des tables est mis de côté (database.sqlite.avant-install-AAAAMMJJHHMMSS) et remis en place si
            // l'installation échoue.
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
            $pdo = null;
            if ($tables > 0) {
                $backup = $path . '.avant-install-' . date('YmdHis');
                if (!@rename($path, $backup) || !@touch($path)) {
                    throw new InstallFailure('install_error_not_writable', ['name' => 'storage/app/']);
                }
                $this->undo[] = static function () use ($path, $backup): void {
                    @unlink($path);
                    @rename($backup, $path);
                };
                return;
            }
            $this->undo[] = static fn () => @file_put_contents($path, '');
            return;
        }

        if (!@touch($path)) {
            throw new InstallFailure('install_error_not_writable', ['name' => 'storage/app/']);
        }
        $this->undo[] = static fn () => @unlink($path);
    }

    private function prepareMysql(InstallInput $in): void
    {
        $server = $this->mysqlServer($in);
        $name = $in->mysqlDatabase; // validé par InstallInput::MYSQL_DATABASE_PATTERN, sans caractère à échapper

        $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$name]);

        if ((int) $exists->fetchColumn() === 0) {
            $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->undo[] = fn () => $this->mysqlServer($in)->exec("DROP DATABASE IF EXISTS `{$name}`");
            return;
        }

        // Base créée à l'avance (hébergement mutualisé, sans droit CREATE DATABASE) : acceptée si elle est vide.
        $tables = $server->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $tables->execute([$name]);
        if ((int) $tables->fetchColumn() > 0) {
            throw new InstallFailure('install_error_database_not_empty', ['name' => $name]);
        }
        $this->undo[] = fn () => $this->dropMysqlTables($in);
    }

    private function createOwner(Application $app, InstallInput $in): int
    {
        $now = date('Y-m-d H:i:s');
        $owner = User::put([
            'first_name'    => $in->firstName,
            'last_name'     => $in->lastName,
            'display_name'  => $in->firstName . ' ' . $in->lastName,
            'email'         => $in->email,
            'password_hash' => PasswordHasher::hash($in->password),
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // AuthService::isAdmin() lit role_assignments (RBAC dynamique) — sans cette affectation, le compte créé par
        // l'installateur ne serait pas reconnu comme Owner.
        $ownerRole = Role::findBySlug('owner');
        if ($ownerRole === null) {
            throw new \RuntimeException('Rôle « owner » introuvable après les migrations.');
        }
        $app->make(RoleAssignment::class)
            ->assign((int) $owner['id'], (int) $ownerRole['id'], 'global', null);

        return (int) $owner['id'];
    }

    /** Dossier d'une instance Kintai installée, différent de celui-ci ; renvoyé sous sa forme canonique. */
    private function importSource(string $dir): string
    {
        $source = realpath($dir);
        if ($source === false || !is_dir($source)) {
            throw new InstallFailure('install_import_error_not_found', ['name' => $dir]);
        }
        if ($source === realpath($this->basePath)) {
            throw new InstallFailure('install_import_error_same_folder');
        }
        $kintai = is_file($source . '/src/Core/Application.php') || is_file($source . '/app/Core/Application.php');
        if (!$kintai || !is_file($source . '/config/database.local.php')) {
            throw new InstallFailure('install_import_error_not_kintai', ['name' => $source]);
        }
        return $source;
    }

    /** @return array{driver:string, connections:array<string, mixed>} config/database.local.php de l'instance importée */
    private function importDatabaseConfig(string $source): array
    {
        $config = (static fn (string $file): mixed => require $file)($source . '/config/database.local.php');
        if (!is_array($config) || !in_array($config['driver'] ?? null, ['sqlite', 'mysql'], true)) {
            throw new InstallFailure('install_import_error_not_kintai', ['name' => $source]);
        }
        $config['connections'] = is_array($config['connections'] ?? null) ? $config['connections'] : [];
        return $config;
    }

    /** Fichier SQLite de l'instance importée : celui de sa configuration, sinon l'emplacement par défaut. */
    private function sqliteSource(string $source, array $database): string
    {
        $path = $database['connections']['sqlite']['path'] ?? null;
        $file = is_string($path) && $path !== '' ? $path : $source . '/' . self::SQLITE_PATH;
        if (!is_file($file) || filesize($file) === 0) {
            throw new InstallFailure('install_import_error_database', ['name' => $file]);
        }
        return $file;
    }

    /**
     * Copie cohérente de la base SQLite (VACUUM INTO : le journal WAL d'une instance encore ouverte est pris en compte)
     * vers storage/app/database.sqlite, qui doit être absent ou vide.
     */
    private function importSqlite(string $file): void
    {
        $target = $this->basePath . '/' . self::SQLITE_PATH;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new InstallFailure('install_error_not_writable', ['name' => 'storage/app/']);
        }
        if (file_exists($target)) {
            $pdo = new PDO('sqlite:' . $target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
            $pdo = null;
            if ($tables > 0) {
                throw new InstallFailure('install_error_database_not_empty', ['name' => self::SQLITE_PATH]);
            }
            @unlink($target);
            $this->undo[] = static fn () => @file_put_contents($target, '');
        }

        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->undo[] = static fn () => @unlink($target);
        $pdo->prepare('VACUUM INTO ?')->execute([$target]);
        $pdo = null;
    }

    /** Copie récursive de storage/, sans écraser ce qui existe déjà ici (.htaccess, .gitkeep) ni IMPORT_SKIPPED. */
    private function copyTree(string $from, string $to, string $relative): void
    {
        if (!is_dir($from)) {
            return;
        }
        if (!is_dir($to)) {
            if (!@mkdir($to, 0755, true)) {
                throw new InstallFailure('install_error_not_writable', ['name' => 'storage/' . $relative]);
            }
            $this->undo[] = static fn () => @rmdir($to);
        }
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $relative === '' ? $item : $relative . '/' . $item;
            if (in_array($child, self::IMPORT_SKIPPED, true)) {
                continue;
            }
            if (is_dir($from . '/' . $item)) {
                $this->copyTree($from . '/' . $item, $to . '/' . $item, $child);
            } elseif (!file_exists($to . '/' . $item)) {
                $target = $to . '/' . $item;
                if (!@copy($from . '/' . $item, $target)) {
                    throw new InstallFailure('install_error_not_writable', ['name' => 'storage/' . $child]);
                }
                $this->undo[] = static fn () => @unlink($target);
            }
        }
    }

    // ─── Annulation ───────────────────────────────────────────────────────────

    private function rollback(): void
    {
        // Connexions Eloquent fermées d'abord : sous Windows, un fichier SQLite ouvert ne peut pas être supprimé.
        try {
            Capsule::connection()->disconnect();
        } catch (Throwable) {
            // Aucune connexion ouverte (échec avant le démarrage de l'application).
        }

        foreach (array_reverse($this->undo) as $undo) {
            try {
                $undo();
            } catch (Throwable $e) {
                $this->log($e);
            }
        }
        $this->undo = [];
    }

    /** Écrit un fichier (chemin relatif) en enregistrant son contenu d'avant. */
    private function writeFile(string $relative, string $content): void
    {
        $path = $this->basePath . '/' . $relative;
        $this->rememberFile($path);
        if (@file_put_contents($path, $content) === false) {
            throw new InstallFailure('install_error_not_writable', ['name' => $relative]);
        }
    }

    /** Annulation d'une écriture de fichier : contenu d'avant restauré, ou fichier supprimé s'il n'existait pas. */
    private function rememberFile(string $path): void
    {
        $previous = file_exists($path) ? (string) file_get_contents($path) : null;
        $this->undo[] = static function () use ($path, $previous): void {
            $previous === null ? @unlink($path) : @file_put_contents($path, $previous);
        };
    }

    private function dropMysqlTables(InstallInput $in): void
    {
        $pdo = $this->mysqlServer($in);
        $list = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $list->execute([$in->mysqlDatabase]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($list->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $in->mysqlDatabase . '`.`' . str_replace('`', '``', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ─── Outils ───────────────────────────────────────────────────────────────

    /** @return array<string, string> variables DB_* du fichier .env */
    private function databaseEnv(InstallInput $in): array
    {
        $env = ['DB_CONNECTION' => $in->driver];
        if ($in->driver === 'mysql') {
            $env += [
                'DB_HOST'     => $in->mysqlHost,
                'DB_PORT'     => (string) $in->mysqlPort,
                'DB_DATABASE' => $in->mysqlDatabase,
                'DB_USERNAME' => $in->mysqlUsername,
                'DB_PASSWORD' => $in->mysqlPassword,
            ];
        }
        return $env;
    }

    /**
     * Variables DB_* d'une instance importée, d'après son ancien config/database.local.php.
     *
     * @param array{driver:string, connections:array<string, mixed>} $database
     * @return array<string, string>
     */
    private function importedDatabaseEnv(array $database): array
    {
        $env = ['DB_CONNECTION' => $database['driver']];
        if ($database['driver'] === 'mysql') {
            $mysql = $database['connections']['mysql'] ?? [];
            $env += [
                'DB_HOST'     => (string) ($mysql['host'] ?? '127.0.0.1'),
                'DB_PORT'     => (string) ($mysql['port'] ?? 3306),
                'DB_DATABASE' => (string) ($mysql['database'] ?? 'kintai'),
                'DB_USERNAME' => (string) ($mysql['username'] ?? 'root'),
                'DB_PASSWORD' => (string) ($mysql['password'] ?? ''),
            ];
            if ((string) ($mysql['prefix'] ?? '') !== '') {
                $env['DB_PREFIX'] = (string) $mysql['prefix'];
            }
        }
        return $env;
    }

    /** Écrit les variables DB_* dans le fichier d'environnement (restauré si l'installation échoue). */
    private function writeDatabaseEnv(array $variables): void
    {
        $env = new EnvFileWriter($this->basePath);
        $this->rememberFile($env->path());
        try {
            foreach ($variables as $key => $value) {
                $env->set($key, $value);
            }
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw new InstallFailure('install_error_not_writable', ['name' => basename($env->path())], $e);
        }
    }

    /** Connexion au serveur MySQL, sans base sélectionnée. */
    private function mysqlServer(InstallInput $in): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $in->mysqlHost, $in->mysqlPort),
            $in->mysqlUsername,
            $in->mysqlPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    /** Même format que Application::logError() : le détail lisible dans /admin/activity (onglet error.log). */
    private function log(Throwable $e): void
    {
        $dir = $this->basePath . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $root = $e instanceof InstallFailure && $e->getPrevious() !== null ? $e->getPrevious() : $e;
        @file_put_contents($dir . '/error.log', sprintf(
            "[%s] Installation — %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($root),
            $root->getMessage(),
            $root->getFile(),
            $root->getLine(),
            $root->getTraceAsString(),
        ), FILE_APPEND | LOCK_EX);
    }
}
