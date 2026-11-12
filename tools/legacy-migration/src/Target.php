<?php

declare(strict_types=1);

namespace KintaiLegacy;

use PDO;

/** Base du nouveau projet (schéma déjà migré par `php artisan migrate`), écrite par PDO. */
final class Target
{
    /** Tables qui doivent être vides : la migration ne fusionne jamais. */
    private const MUST_BE_EMPTY = ['users', 'stores', 'shifts', 'shift_types'];

    /** @var array<string, \PDOStatement> */
    private array $statements = [];

    private bool $savepoint = false;

    public function __construct(
        public readonly PDO $pdo,
        public readonly string $driver,
        /** Racine du disque `local` de l'application (storage/app/private), où vont les avatars. */
        public readonly string $storageDir,
        /** APP_KEY de la cible : sert à rechiffrer les secrets repris (jeton Google) ; null = ces secrets ne peuvent pas être repris. */
        #[\SensitiveParameter] public readonly ?string $appKey = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** Se connecte avec les variables DB_* d'un fichier `.env` (lu comme des données, jamais exécuté). */
    public static function fromEnvFile(string $envFile): self
    {
        if (! is_file($envFile)) {
            throw new MigrationException("Fichier d'environnement de la cible introuvable : {$envFile}");
        }
        $env = self::parseEnv((string) file_get_contents($envFile));
        $base = dirname((string) realpath($envFile));
        $driver = $env['DB_CONNECTION'] ?? 'sqlite';
        if (($env['DB_PREFIX'] ?? '') !== '') {
            throw new MigrationException('DB_PREFIX n\'est pas pris en charge par l\'outil de migration.');
        }

        if ($driver === 'sqlite') {
            $database = $env['DB_DATABASE'] ?? '';
            $database = $database === '' ? $base.'/database/database.sqlite' : $database;
            $absolute = preg_match('#^([A-Za-z]:)?[\\\\/]#', $database) === 1;
            $path = $absolute ? $database : $base.'/'.$database;
            if (! is_file($path)) {
                throw new MigrationException("Base SQLite de la cible introuvable : {$path} (lancez d'abord php artisan migrate).");
            }
            $pdo = new PDO('sqlite:'.$path);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $dsn = 'mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.(int) ($env['DB_PORT'] ?? 3306).';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4';
            try {
                $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '');
            } catch (\PDOException $e) {
                throw new MigrationException('Connexion MySQL à la cible impossible : '.$e->getMessage());
            }
            $driver = 'mysql';
        } else {
            throw new MigrationException("Pilote de base de la cible non pris en charge : {$driver} (sqlite, mysql ou mariadb).");
        }

        return new self($pdo, $driver, $base.'/storage/app/private', ($env['APP_KEY'] ?? '') !== '' ? $env['APP_KEY'] : null);
    }

    /** @return array<string, string> */
    public static function parseEnv(string $content): array
    {
        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim(preg_replace('/^export\s+/', '', trim($key)) ?? '');
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? '');
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** Refuse une cible dont le schéma n'est pas migré ou qui contient déjà des données. */
    public function assertReady(): void
    {
        foreach (array_merge(self::MUST_BE_EMPTY, ['roles', 'role_permissions', 'role_user', 'store_user', 'store_features',
            'shift_type_store', 'shift_type_user', 'availabilities', 'employee_profiles', 'app_settings']) as $table) {
            if (! $this->tableExists($table)) {
                throw new MigrationException("Cible : la table {$table} n'existe pas. Lancez d'abord php artisan migrate sur la cible.");
            }
        }
        foreach (self::MUST_BE_EMPTY as $table) {
            if ($this->count($table) > 0) {
                throw new MigrationException("Cible non vide ({$table} contient des lignes) : l'import ne fusionne jamais. Utilisez une base fraîchement migrée, sans assistant d'installation.");
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? [] : $statement->fetchAll();
    }

    /** @param  array<string, mixed>  $row */
    public function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $key = $table.'|'.implode(',', $columns);
        $this->statements[$key] ??= $this->pdo->prepare(
            'INSERT INTO '.$this->quote($table).' ('.implode(', ', array_map($this->quote(...), $columns)).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')'
        );
        $this->statements[$key]->execute(array_values($row));
    }

    /** @param  list<mixed>  $bindings */
    public function run(string $sql, array $bindings = []): void
    {
        $this->pdo->prepare($sql)->execute($bindings);
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->quote($table))->fetchColumn();
    }

    public function tableExists(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        } else {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        }
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function quote(string $identifier): string
    {
        return $this->driver === 'mysql' ? '`'.$identifier.'`' : '"'.$identifier.'"';
    }

    /** Tout ou rien : transaction, ou point de sauvegarde si l'appelant en a déjà ouvert une. */
    public function begin(): void
    {
        $this->savepoint = $this->pdo->inTransaction();
        if ($this->savepoint) {
            $this->pdo->exec('SAVEPOINT legacy_migration');
        } else {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->savepoint) {
            $this->pdo->exec('RELEASE SAVEPOINT legacy_migration');
        } else {
            $this->pdo->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->savepoint) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT legacy_migration');
            $this->pdo->exec('RELEASE SAVEPOINT legacy_migration');
        } elseif ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
