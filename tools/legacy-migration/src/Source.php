<?php

declare(strict_types=1);

namespace KintaiLegacy;

use PDO;

/**
 * Base de l'ancienne version, ouverte en lecture seule. Seul le schéma actuel de la branche `develop` est pris en
 * charge : toute table ou colonne attendue qui manque fait refuser la source.
 */
final class Source
{
    /** @var array<string, list<string>> colonnes lues, par table */
    public const REQUIRED = [
        'users' => ['id', 'email', 'password_hash', 'first_name', 'last_name', 'display_name', 'phone', 'mobile_phone', 'color',
            'is_active', 'created_at', 'updated_at', 'deleted_at', 'employee_code', 'language', 'gender', 'tax_classification',
            'birth_date', 'education', 'postal_code', 'address', 'guarantor_name', 'guarantor_phone', 'furigana_last_name',
            'furigana_first_name', 'avatar_path', 'share_email', 'share_phone', 'share_mobile_phone'],
        'stores' => ['id', 'name', 'code', 'timezone', 'currency', 'currency_symbol_style', 'locale', 'address_street',
            'address_city', 'address_postal', 'address_country', 'phone', 'email', 'is_active', 'week_start_day',
            'low_staff_hour_start', 'low_staff_hour_end', 'min_staff_per_day', 'min_shift_minutes', 'max_shift_minutes',
            'created_at', 'updated_at', 'deleted_at'],
        'store_user' => ['id', 'store_id', 'user_id', 'staff_code', 'hire_date', 'contract_type', 'is_active', 'created_at', 'updated_at'],
        'store_features' => ['store_id', 'feature_key', 'enabled'],
        'shift_types' => ['id', 'name', 'code', 'start_time', 'end_time', 'hourly_rate', 'color', 'sort_order', 'is_active', 'created_at', 'updated_at'],
        'shift_type_stores' => ['shift_type_id', 'store_id'],
        'user_shift_type_rates' => ['id', 'user_id', 'shift_type_id', 'hourly_rate', 'created_at', 'updated_at'],
        'shifts' => ['id', 'store_id', 'user_id', 'shift_type_id', 'shift_date', 'start_time', 'end_time', 'cross_midnight',
            'pause_minutes', 'starts_at', 'ends_at', 'notes', 'open_shift_note', 'is_open', 'hourly_rate_override',
            'net_minutes_override', 'ical_sequence', 'created_by', 'created_at', 'updated_at', 'deleted_at'],
        'availabilities' => ['id', 'user_id', 'store_id', 'day_of_week', 'start_time', 'end_time', 'is_available', 'created_at', 'updated_at'],
        'roles' => ['id', 'name', 'slug', 'color', 'description', 'is_system', 'is_manager', 'created_at', 'updated_at'],
        'role_permissions' => ['role_id', 'permission_key', 'scope'],
        'role_assignments' => ['id', 'user_id', 'role_id', 'scope_type', 'scope_id', 'created_at'],
        'app_settings' => ['key', 'value'],
        'ical_tokens' => ['id', 'user_id', 'store_id'],
    ];

    public function __construct(
        public readonly PDO $pdo,
        public readonly string $driver,
        /** Dossier des fichiers envoyés (contient avatars/), ou null. */
        public readonly ?string $uploadsDir = null,
        /** Fichier SQLite lu, ou null (MySQL). */
        public readonly ?string $file = null,
    ) {}

    /** Ouvre un fichier SQLite en lecture seule (en-tête contrôlé, jamais modifié). */
    public static function sqlite(string $file, ?string $uploadsDir = null): self
    {
        $real = realpath($file);
        if ($real === false || ! is_file($real) || filesize($real) === 0) {
            throw new MigrationException("Base SQLite source introuvable ou vide : {$file}");
        }
        $handle = fopen($real, 'rb');
        $header = $handle === false ? '' : (string) fread($handle, 16);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($header !== "SQLite format 3\0") {
            throw new MigrationException("Le fichier source n'est pas une base SQLite : {$file}");
        }

        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('PDO::SQLITE_OPEN_READONLY')) {
            $options[constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = constant('PDO::SQLITE_OPEN_READONLY');
        }
        $pdo = new PDO('sqlite:'.$real, null, null, $options);
        $pdo->exec('PRAGMA query_only = ON');

        return new self($pdo, 'sqlite', $uploadsDir, $real);
    }

    /**
     * Ouvre l'instance installée dans un dossier : sa base est lue dans `config/database.local.php`, traité comme des
     * données (jamais exécuté). Le fichier SQLite doit se trouver dans ce dossier.
     */
    public static function fromInstance(string $dir): self
    {
        $root = realpath($dir);
        if ($root === false || ! is_dir($root)) {
            throw new MigrationException("Dossier source introuvable : {$dir}");
        }
        $isKintai = is_file($root.'/src/Core/Application.php') || is_file($root.'/app/Core/Application.php');
        $configFile = $root.'/config/database.local.php';
        if (! $isKintai || ! is_file($configFile)) {
            throw new MigrationException("Ce dossier n'est pas une instance de l'ancienne version de Kintai : {$root}");
        }
        $config = LiteralConfig::parse((string) file_get_contents($configFile));
        if ($config === null || ! in_array($config['driver'] ?? null, ['sqlite', 'mysql'], true)) {
            throw new MigrationException("config/database.local.php est illisible : seules des valeurs littérales sont acceptées (le fichier n'est jamais exécuté).");
        }
        $connections = is_array($config['connections'] ?? null) ? $config['connections'] : [];
        $uploads = $root.'/storage/uploads';

        if ($config['driver'] === 'sqlite') {
            $path = is_array($connections['sqlite'] ?? null) ? ($connections['sqlite']['path'] ?? null) : null;
            $file = is_string($path) && $path !== '' ? $path : $root.'/storage/app/database.sqlite';
            $real = realpath($file);
            if ($real === false) {
                throw new MigrationException("Base SQLite de l'instance introuvable : {$file}");
            }
            $inside = rtrim(str_replace('\\', '/', $root), '/').'/';
            if (! str_starts_with(str_replace('\\', '/', $real), $inside)) {
                throw new MigrationException("La base SQLite déclarée ({$real}) est hors du dossier de l'instance ; indiquez-la explicitement avec --source-db.");
            }

            return self::sqlite($real, $uploads);
        }

        $mysql = is_array($connections['mysql'] ?? null) ? $connections['mysql'] : [];
        $host = (string) ($mysql['host'] ?? '127.0.0.1');
        $name = (string) ($mysql['database'] ?? '');
        if ($name === '' || preg_match('/^[A-Za-z0-9_.\-]+$/', $host) !== 1 || preg_match('/^[A-Za-z0-9_$\-]+$/', $name) !== 1) {
            throw new MigrationException('Réglages MySQL de la source invalides.');
        }
        $dsn = 'mysql:host='.$host.';port='.(int) ($mysql['port'] ?? 3306).';dbname='.$name.';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, (string) ($mysql['username'] ?? 'root'), (string) ($mysql['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (\PDOException $e) {
            throw new MigrationException('Connexion MySQL à la source impossible : '.$e->getMessage());
        }

        return new self($pdo, 'mysql', $uploads);
    }

    /** Refuse une base dont le schéma n'est pas celui de la version prise en charge. */
    public function assertSupportedSchema(): void
    {
        $missingTables = [];
        $missingColumns = [];
        foreach (self::REQUIRED as $table => $columns) {
            $present = $this->columns($table);
            if ($present === []) {
                $missingTables[] = "table {$table}";

                continue;
            }
            foreach (array_diff($columns, $present) as $column) {
                $missingColumns[] = "colonne {$table}.{$column}";
            }
        }
        $missing = array_merge($missingTables, $missingColumns);
        if ($missing !== []) {
            throw new MigrationException(
                'Schéma source non pris en charge (manque : '.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? ', …' : '').'). '
                ."Seul le schéma actuel de la branche develop est lu : mettez d'abord l'ancienne instance à jour (php scripts/db-migrate.php), ou la source n'est pas une base de Kintai."
            );
        }
    }

    /**
     * @param  list<string>  $columns
     * @return \Generator<int, array<string, mixed>>
     */
    public function rows(string $table, array $columns, string $order = 'id'): \Generator
    {
        $select = implode(', ', array_map($this->quote(...), $columns));
        $statement = $this->pdo->query("SELECT {$select} FROM ".$this->quote($table).' ORDER BY '.$this->quote($order));
        if ($statement === false) {
            return;
        }
        foreach ($statement as $row) {
            yield $row;
        }
    }

    public function setting(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT '.$this->quote('value').' FROM '.$this->quote('app_settings').' WHERE '.$this->quote('key').' = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->quote($table))->fetchColumn();
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if ($this->driver === 'sqlite') {
            $rows = $this->pdo->query('PRAGMA table_info('.$this->quote($table).')');

            return $rows === false ? [] : array_map(static fn (array $row): string => (string) $row['name'], $rows->fetchAll());
        }
        $statement = $this->pdo->prepare('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);

        return array_map(static fn (array $row): string => (string) $row['name'], $statement->fetchAll());
    }

    private function quote(string $identifier): string
    {
        return $this->driver === 'mysql' ? '`'.$identifier.'`' : '"'.$identifier.'"';
    }
}
