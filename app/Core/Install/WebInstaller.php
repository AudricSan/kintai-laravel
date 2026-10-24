<?php

declare(strict_types=1);

namespace kintai\Core\Install;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Application;
use kintai\Core\Auth\PasswordHasher;
use kintai\Core\Database\DemoDataSeeder;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\EnvFileWriter;
use kintai\Core\Services\InstanceLocale;
use kintai\Core\Services\PublicUrlResolver;
use kintai\Core\Services\SetupWizard;
use PDO;
use Throwable;

/**
 * Installation d'une instance neuve, appelée par public/install.php : fichier d'environnement, base de données,
 * migrations, compte Owner, verrou.
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

            $in->driver === 'mysql' ? $this->prepareMysql($in) : $this->prepareSqlite();

            $this->writeFile('config/database.local.php', '<?php return ' . var_export($this->databaseConfig($in), true) . ';' . PHP_EOL);

            $app = new Application($this->basePath);
            $app->boot();
            (new MigrationRunner($app))->run();

            if ($in->demoData) {
                // Données de démonstration du Core, écrites par les modèles (indépendantes du pilote et du schéma).
                (new DemoDataSeeder())->run();
            }

            $ownerId = $this->createOwner($app, $in);

            $settings = $app->container()->make(AppSettingsRepositoryInterface::class);
            // Sert aux liens absolus des e-mails ; modifiable dans /admin/owner-settings, APP_URL prime (PublicUrlResolver).
            if ($publicUrl !== null) {
                $settings->setMany([PublicUrlResolver::SETTING_KEY => $publicUrl]);
            }
            // Aucun bundle actif au départ ; l'Owner choisit sa licence puis ses bundles dans /admin/setup.
            SetupWizard::start($settings, $app->container()->make(AppSettingsService::class)->updateChannel());

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

    // ─── Étapes ───────────────────────────────────────────────────────────────

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
            // Fichier laissé par une ancienne installation : accepté seulement s'il est vide de toute table.
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
            $pdo = null;
            if ($tables > 0) {
                throw new InstallFailure('install_error_database_not_empty', ['name' => self::SQLITE_PATH]);
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
        $owner = $app->container()->make(UserRepositoryInterface::class)->save([
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
        $ownerRole = $app->container()->make(RoleRepositoryInterface::class)->findBySlug('owner');
        if ($ownerRole === null) {
            throw new \RuntimeException('Rôle « owner » introuvable après les migrations.');
        }
        $app->container()->make(RoleAssignmentRepositoryInterface::class)
            ->assign((int) $owner['id'], (int) $ownerRole['id'], 'global', null);

        return (int) $owner['id'];
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

    /** @return array<string, mixed> contenu de config/database.local.php */
    private function databaseConfig(InstallInput $in): array
    {
        $config = ['driver' => $in->driver, 'connections' => []];
        if ($in->driver === 'mysql') {
            $config['connections']['mysql'] = [
                'host'      => $in->mysqlHost,
                'port'      => $in->mysqlPort,
                'database'  => $in->mysqlDatabase,
                'username'  => $in->mysqlUsername,
                'password'  => $in->mysqlPassword,
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ];
        }
        return $config;
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
