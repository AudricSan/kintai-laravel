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

    /**
     * Tables des bundles (pointage, congés, reprises et échanges de quarts) : absentes si le bundle n'a jamais été
     * installé, ce qui n'est pas une erreur ; présentes, elles doivent avoir toutes les colonnes lues.
     *
     * @var array<string, list<string>>
     */
    public const OPTIONAL = [
        'timeclocks' => ['id', 'user_id', 'store_id', 'shift_id', 'shift_date', 'clock_in_time', 'clock_out_time', 'duration_minutes',
            'clock_in_ip', 'clock_out_ip', 'notes', 'created_at', 'updated_at'],
        'timeoff_requests' => ['id', 'user_id', 'store_id', 'start_date', 'end_date', 'type', 'status', 'reason', 'admin_note',
            'processed_by', 'processed_at', 'created_at', 'updated_at'],
        'shift_claims' => ['id', 'shift_id', 'user_id', 'store_id', 'status', 'note', 'claimed_at', 'resolved_at', 'resolved_by',
            'created_at', 'updated_at'],
        'shift_swap_requests' => ['id', 'store_id', 'requester_id', 'target_user_id', 'requester_shift_id', 'target_shift_id', 'status',
            'reason', 'accepted_by_id', 'accepted_at', 'approved_by_id', 'approved_at', 'created_at', 'updated_at'],
        'hiring_reports' => ['id', 'store_id', 'user_id', 'employee_number', 'employee_name', 'furigana_last_name', 'furigana_first_name',
            'gender', 'tax_classification', 'birth_date', 'hire_date', 'education', 'postal_code', 'address', 'phone', 'mobile_phone',
            'email', 'guarantor_name', 'guarantor_phone', 'store_name', 'hired_by', 'notes', 'created_by', 'created_at', 'updated_at'],
        'resignation_reports' => ['id', 'store_id', 'user_id', 'employee_number', 'employee_name', 'resignation_date', 'reason',
            'resignation_notice', 'notes', 'person_in_charge', 'created_by', 'created_at', 'updated_at'],
        'daily_reports' => ['id', 'store_id', 'author_id', 'report_date', 'sales_total', 'customer_count', 'labor_cost', 'waste_total',
            'notes', 'status', 'submitted_at', 'validated_by', 'validated_at', 'mail_sent_at', 'created_at', 'updated_at', 'deleted_at'],
        'salary_reports' => ['id', 'store_id', 'user_id', 'target_month', 'person_in_charge', 'total_payment', 'total_deductions',
            'income_tax_base', 'withholding_tax', 'residence_tax', 'other_deductions', 'net_payment', 'active_employees',
            'hand_delivered_salary', 'bank_transfer_salary', 'staff_man_hours', 'staff_total_payment', 'staff_avg_hourly_wage',
            'employee_work_hours', 'new_hires', 'resigned_staff', 'hire_registrations', 'remarks', 'created_by', 'created_at', 'updated_at'],
        'store_deduction_settings' => ['id', 'store_id', 'enabled', 'health_insurance_rate', 'pension_rate', 'employment_insurance_rate',
            'income_tax_rate', 'resident_tax_monthly', 'created_at', 'updated_at'],
        'message_threads' => ['id', 'store_id', 'creator_id', 'subject', 'last_message_at', 'created_at', 'updated_at'],
        'thread_participants' => ['id', 'thread_id', 'user_id', 'is_read', 'last_read_at', 'created_at'],
        'thread_messages' => ['id', 'thread_id', 'sender_id', 'body', 'created_at'],
        'store_photo_submissions' => ['id', 'store_id', 'notes', 'deleted_at', 'created_by', 'created_at', 'updated_at'],
        'store_photo_images' => ['id', 'submission_id', 'filename', 'filepath', 'sort_order', 'created_at'],
        'calendar_connections' => ['id', 'user_id', 'provider', 'account_email', 'refresh_token', 'calendar_id', 'calendar_name', 'status',
            'last_sync_at', 'last_error', 'options', 'created_at', 'updated_at'],
        'calendar_event_links' => ['id', 'connection_id', 'source_type', 'source_id', 'external_event_id', 'content_hash', 'synced_at'],
        'calendar_sync_queue' => ['id', 'connection_id', 'source_type', 'source_id', 'attempts', 'next_attempt_at', 'last_error', 'created_at'],
        'store_import_settings' => ['id', 'store_id', 'col_start', 'col_end', 'base_hour', 'minutes_per_col', 'block_size', 'shift_rows',
            'sheet_filter_pattern', 'auto_pause_after_minutes', 'auto_pause_minutes', 'created_at', 'updated_at'],
        'import_aliases' => ['id', 'store_id', 'user_id', 'created_at'],
    ];

    /**
     * Colonnes dont une seule est exigée (renommage dans l'ancienne version) : une base ancienne a `alias_name`, une base à jour
     * `staff_name`. Lues dans l'ordre de préférence.
     *
     * @var array<string, list<string>>
     */
    public const ONE_OF = [
        'import_aliases' => ['staff_name', 'alias_name'],
    ];

    /** Colonnes facultatives de `users` (annuaire) : lues seulement si elles existent. */
    public const DIRECTORY_COLUMNS = ['bio', 'skills', 'languages_spoken', 'hobbies', 'show_in_directory'];

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
        foreach (self::OPTIONAL as $table => $columns) {
            $present = $this->columns($table);
            if ($present === []) {
                continue; // bundle jamais installé : l'étape correspondante est sautée
            }
            foreach (array_diff($columns, $present) as $column) {
                $missingColumns[] = "colonne {$table}.{$column}";
            }
            if (isset(self::ONE_OF[$table]) && array_intersect(self::ONE_OF[$table], $present) === []) {
                $missingColumns[] = "colonne {$table}.".implode(' ou ', self::ONE_OF[$table]);
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

    /** Secret d'instance (`instance_secret`), dont est dérivée la clé de chiffrement des secrets ; null s'il est absent. */
    public function instanceSecret(): ?string
    {
        return $this->setting('instance_secret');
    }

    public function hasTable(string $table): bool
    {
        return $this->columns($table) !== [];
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
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
