<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests\Support;

use KintaiLegacy\Source;
use PDO;

/**
 * Instance de l'ancienne version entièrement synthétique (aucune vraie donnée) : une base SQLite dont les tables ont
 * les colonnes lues par l'outil (plus quelques colonnes ignorées), des données d'exemple et des fichiers d'avatar.
 */
final class SyntheticSource
{
    public const PASSWORD = 'Secret-Passw0rd!';

    public string $directory;

    public string $database;

    public string $uploads;

    public PDO $pdo;

    /** Colonnes ignorées par l'outil, ajoutées pour ressembler à l'ancien schéma. */
    private const EXTRA = ['users' => ['last_login_at', 'bio', 'skills'], 'stores' => ['type'], 'shifts' => ['estimated_salary']];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/kintai-legacy-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/storage/uploads/avatars', 0777, true);
        mkdir($this->directory.'/storage/app', 0777, true);
        mkdir($this->directory.'/config', 0777, true);
        mkdir($this->directory.'/src/Core', 0777, true);
        $this->uploads = $this->directory.'/storage/uploads';
        $this->database = $this->directory.'/storage/app/database.sqlite';
        file_put_contents($this->directory.'/src/Core/Application.php', '<?php // synthetique');
        file_put_contents($this->directory.'/config/database.local.php', "<?php\nreturn ['driver' => 'sqlite', 'connections' => ['sqlite' => ['path' => '".str_replace('\\', '/', $this->database)."']]];\n");

        $this->pdo = new PDO('sqlite:'.$this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createSchema();
        $this->seed();
        $this->pdo = new PDO('sqlite:'.$this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function source(): Source
    {
        return Source::sqlite($this->database, $this->uploads);
    }

    public function cleanup(): void
    {
        $this->rrmdir($this->directory);
    }

    public function png(string $name): void
    {
        // PNG 1x1 valide.
        file_put_contents($this->uploads.'/avatars/'.$name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function createSchema(): void
    {
        foreach (Source::REQUIRED as $table => $columns) {
            $definitions = [];
            foreach (array_merge($columns, self::EXTRA[$table] ?? []) as $column) {
                $definitions[] = $column === 'id' ? 'id INTEGER PRIMARY KEY' : '"'.$column.'" TEXT';
            }
            $this->pdo->exec('CREATE TABLE "'.$table.'" ('.implode(', ', $definitions).')');
        }
    }

    private function seed(): void
    {
        $hash = fn (string $password): string => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->png('owner.png');
        file_put_contents($this->directory.'/storage/uploads/secret.png', 'hors du dossier des avatars');

        $this->insert('users', ['id' => 1, 'email' => 'owner@example.test', 'password_hash' => $hash(self::PASSWORD), 'first_name' => 'Ada', 'last_name' => 'Owner',
            'display_name' => 'Ada Owner', 'phone' => '0100000001', 'mobile_phone' => '0600000001', 'color' => '#112233', 'is_active' => 1, 'created_at' => '2026-03-01 09:00:00',
            'updated_at' => '2026-03-02 09:00:00', 'language' => 'fr', 'gender' => 'F', 'birth_date' => '1990-05-17', 'avatar_path' => 'owner.png', 'share_email' => 1,
            'share_phone' => 0, 'share_mobile_phone' => 0, 'furigana_last_name' => 'オーナー', 'last_login_at' => '2026-03-03 10:00:00', 'bio' => 'ignorée']);
        $this->insert('users', ['id' => 2, 'email' => 'manager@example.test', 'password_hash' => $hash('0000'), 'first_name' => 'Max', 'last_name' => 'Manager',
            'display_name' => 'Max Manager', 'color' => 'rouge', 'is_active' => 1, 'created_at' => '2026-03-01 09:00:00', 'language' => '', 'avatar_path' => '../secret.png']);
        $this->insert('users', ['id' => 3, 'email' => 'employee@example.test', 'password_hash' => $hash('long-enough-pass'), 'first_name' => 'Eve', 'last_name' => 'Employee',
            'display_name' => 'Eve Employee', 'employee_code' => 'E003', 'is_active' => 1, 'created_at' => '2026-03-01 09:00:00', 'avatar_path' => 'gone.png']);
        $this->insert('users', ['id' => 4, 'email' => 'broken@example.test', 'password_hash' => 'not-a-hash', 'first_name' => 'Bob', 'last_name' => 'Broken',
            'display_name' => '', 'is_active' => 0, 'created_at' => '2026-03-01 09:00:00']);
        $this->insert('users', ['id' => 5, 'email' => 'deleted-5@kintai.local', 'password_hash' => 'x', 'first_name' => '', 'last_name' => '', 'display_name' => 'Anonymisé',
            'is_active' => 0, 'created_at' => '2026-03-01 09:00:00', 'deleted_at' => '2026-04-01 12:00:00']);

        $this->insert('stores', ['id' => 1, 'name' => 'Boutique A', 'code' => 'A', 'timezone' => 'Asia/Tokyo', 'currency' => 'jpy', 'currency_symbol_style' => 'kanji', 'locale' => 'ja',
            'is_active' => 1, 'week_start_day' => 1, 'low_staff_hour_start' => -1, 'low_staff_hour_end' => -1, 'min_staff_per_day' => 2, 'min_shift_minutes' => 120,
            'max_shift_minutes' => 480, 'created_at' => '2026-03-01 09:00:00', 'address_city' => 'Tokyo']);
        $this->insert('stores', ['id' => 2, 'name' => 'Boutique B', 'code' => 'B', 'timezone' => 'UTC', 'currency' => 'EUR', 'locale' => 'en', 'is_active' => 1, 'week_start_day' => 0,
            'low_staff_hour_start' => 14, 'low_staff_hour_end' => 18, 'min_staff_per_day' => 0, 'min_shift_minutes' => 60, 'max_shift_minutes' => 600]);
        $this->insert('store_user', ['id' => 1, 'store_id' => 1, 'user_id' => 2, 'staff_code' => 'M1', 'hire_date' => '2026-01-15', 'contract_type' => 'cdi', 'is_active' => 1]);
        $this->insert('store_user', ['id' => 2, 'store_id' => 1, 'user_id' => 3, 'staff_code' => 'E3', 'hire_date' => 'pas une date', 'is_active' => 1]);
        $this->insert('store_user', ['id' => 3, 'store_id' => 2, 'user_id' => 3, 'is_active' => 0]);
        $this->insert('store_user', ['id' => 4, 'store_id' => 1, 'user_id' => 99, 'is_active' => 1]);
        $this->insert('store_features', ['store_id' => 1, 'feature_key' => 'timeclock', 'enabled' => 0]);

        $this->insert('roles', ['id' => 1, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => 1, 'is_manager' => 0]);
        $this->insert('roles', ['id' => 2, 'name' => 'Manager', 'slug' => 'manager', 'color' => '#2563EB', 'is_system' => 0, 'is_manager' => 1]);
        $this->insert('roles', ['id' => 3, 'name' => 'Employé', 'slug' => 'employee', 'is_system' => 0, 'is_manager' => 0]);
        $this->insert('roles', ['id' => 4, 'name' => 'Chef de rayon', 'slug' => 'lead', 'color' => '#0A0B0C', 'is_system' => 0, 'is_manager' => 0]);
        foreach ([[2, 'employees.view', 'local'], [2, 'shifts.view', 'global'], [2, 'timeoff.approve', 'local'], [4, 'stores.view', 'global']] as [$role, $key, $scope]) {
            $this->insert('role_permissions', ['role_id' => $role, 'permission_key' => $key, 'scope' => $scope]);
        }
        $this->insert('role_assignments', ['id' => 1, 'user_id' => 1, 'role_id' => 1, 'scope_type' => 'global', 'scope_id' => null]);
        $this->insert('role_assignments', ['id' => 2, 'user_id' => 2, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]);
        $this->insert('role_assignments', ['id' => 3, 'user_id' => 3, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 1]);
        $this->insert('role_assignments', ['id' => 4, 'user_id' => 3, 'role_id' => 4, 'scope_type' => 'global', 'scope_id' => null]);
        $this->insert('role_assignments', ['id' => 5, 'user_id' => 3, 'role_id' => 3, 'scope_type' => 'planet', 'scope_id' => 1]);
        $this->insert('role_assignments', ['id' => 6, 'user_id' => 3, 'role_id' => 3, 'scope_type' => 'store', 'scope_id' => 77]);

        $this->insert('shift_types', ['id' => 1, 'name' => 'Matin', 'code' => 'AM', 'start_time' => '09:00', 'end_time' => '17:00', 'hourly_rate' => '1200.5', 'color' => '#00FF00', 'sort_order' => 1, 'is_active' => 1]);
        $this->insert('shift_types', ['id' => 2, 'name' => 'Cassé', 'code' => 'XX', 'start_time' => 'matin', 'end_time' => '17:00', 'is_active' => 1]);
        $this->insert('shift_type_stores', ['shift_type_id' => 1, 'store_id' => 1]);
        $this->insert('user_shift_type_rates', ['id' => 1, 'user_id' => 3, 'shift_type_id' => 1, 'hourly_rate' => '1500']);

        // Heures locales de l'instance (Asia/Tokyo, UTC+9, sans changement d'heure).
        $this->insert('shifts', ['id' => 1, 'store_id' => 1, 'user_id' => 3, 'shift_type_id' => 1, 'shift_date' => '2026-03-02', 'start_time' => '09:00', 'end_time' => '17:00',
            'cross_midnight' => 0, 'pause_minutes' => 60, 'starts_at' => '2026-03-02 09:00:00', 'ends_at' => '2026-03-02 17:00:00', 'notes' => 'Caisse', 'ical_sequence' => 2,
            'is_open' => 0, 'created_by' => 2, 'created_at' => '2026-03-01 09:00:00']);
        $this->insert('shifts', ['id' => 2, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-03-03', 'start_time' => '22:00', 'end_time' => '06:00', 'cross_midnight' => 1,
            'starts_at' => '2026-03-03 22:00:00', 'ends_at' => '2026-03-04 06:00:00', 'is_open' => 0]);
        $this->insert('shifts', ['id' => 3, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-03-04', 'start_time' => '10:00', 'end_time' => '14:00', 'cross_midnight' => 0,
            'starts_at' => '2026-03-04 10:00:00', 'ends_at' => '2026-03-04 14:00:00', 'is_open' => 1, 'open_shift_note' => 'À prendre']);
        $this->insert('shifts', ['id' => 4, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-03-05', 'start_time' => '10:00', 'end_time' => '14:00', 'cross_midnight' => 0,
            'starts_at' => '2026-03-05 10:00:00', 'ends_at' => '2026-03-05 14:00:00', 'is_open' => 0, 'deleted_at' => '2026-03-05 15:00:00']);
        $this->insert('shifts', ['id' => 5, 'store_id' => 99, 'user_id' => 3, 'shift_date' => '2026-03-06', 'start_time' => '10:00', 'end_time' => '14:00', 'cross_midnight' => 0,
            'starts_at' => '2026-03-06 10:00:00', 'ends_at' => '2026-03-06 14:00:00', 'is_open' => 0]);
        $this->insert('shifts', ['id' => 6, 'store_id' => 1, 'user_id' => 3, 'shift_date' => '2026-03-07', 'start_time' => '', 'end_time' => '', 'cross_midnight' => 0,
            'starts_at' => '', 'ends_at' => '', 'is_open' => 0]);

        $this->insert('availabilities', ['id' => 1, 'user_id' => 3, 'store_id' => 1, 'day_of_week' => 0, 'start_time' => '00:00', 'end_time' => '23:59', 'is_available' => 0]);
        $this->insert('availabilities', ['id' => 2, 'user_id' => 3, 'store_id' => 1, 'day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '18:00', 'is_available' => 1]);

        $this->insert('app_settings', ['key' => 'app_timezone', 'value' => 'Asia/Tokyo']);
        $this->insert('app_settings', ['key' => 'app_subtitle', 'value' => 'Mon sous-titre']);
        $this->insert('app_settings', ['key' => 'backup_max_keep', 'value' => '7']);
        $this->insert('app_settings', ['key' => 'backup_auto_enabled', 'value' => '0']);
        $this->insert('app_settings', ['key' => 'app_mascot_mode', 'value' => 'on']);

        $this->insert('ical_tokens', ['id' => 1, 'user_id' => 3, 'store_id' => 1]);
        $this->insert('ical_tokens', ['id' => 2, 'user_id' => 2, 'store_id' => 1]);
    }

    /** @param  array<string, mixed>  $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->pdo->prepare('INSERT INTO "'.$table.'" ('.implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columns)).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')')
            ->execute(array_values($row));
    }

    private function rrmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                is_dir($path.'/'.$item) ? $this->rrmdir($path.'/'.$item) : @unlink($path.'/'.$item);
            }
        }
        @rmdir($path);
    }
}
