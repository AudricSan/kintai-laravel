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
    private const EXTRA = ['users' => ['last_login_at', 'bio', 'skills'], 'stores' => ['type'], 'store_user' => ['subject_to_deductions'], 'shifts' => ['estimated_salary'],
        'message_threads' => ['type'], 'import_aliases' => ['staff_name'],
        'store_photo_submissions' => ['week_label', 'image_count', 'retention_days', 'compressed', 'compressed_at', 'mail_sent_at'],
        'store_photo_images' => ['filesize', 'mime_type', 'compressed_filesize', 'width', 'height']];

    /** @param  bool  $bundles  Crée aussi les tables des bundles (pointage, congés, reprises et échanges de quarts) et leurs données. */
    public function __construct(private readonly bool $bundles = true)
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
        $tables = $this->bundles ? Source::REQUIRED + Source::OPTIONAL : Source::REQUIRED;
        foreach ($tables as $table => $columns) {
            $definitions = [];
            // Colonne JSON des réglages du rapport journalier : sur `stores` dans l'ancien schéma, ignorée sans le bundle.
            $extra = array_merge(self::EXTRA[$table] ?? [], $table === 'stores' && $this->bundles ? ['daily_report_settings'] : []);
            foreach (array_merge($columns, $extra) as $column) {
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

        if ($this->bundles) {
            $this->seedBundles();
        }
    }

    /** Données des bundles : cas nominaux, orphelins, doublons et valeurs hors des énumérations de la cible. */
    private function seedBundles(): void
    {
        // Pointages (heures locales Asia/Tokyo).
        $this->insert('timeclocks', ['id' => 1, 'user_id' => 3, 'store_id' => 1, 'shift_id' => 1, 'shift_date' => '2026-03-02', 'clock_in_time' => '2026-03-02 09:02:00',
            'clock_out_time' => '2026-03-02 17:05:00', 'duration_minutes' => 483, 'clock_in_ip' => '10.0.0.1', 'clock_out_ip' => '10.0.0.2', 'notes' => 'RAS',
            'created_at' => '2026-03-02 09:02:00', 'updated_at' => '2026-03-02 17:05:00']);
        $this->insert('timeclocks', ['id' => 2, 'user_id' => 99, 'store_id' => 1, 'shift_date' => '2026-03-02', 'clock_in_time' => '2026-03-02 09:00:00']);
        $this->insert('timeclocks', ['id' => 3, 'user_id' => 3, 'store_id' => 1, 'shift_date' => '2026-03-03', 'clock_in_time' => '']);
        $this->insert('timeclocks', ['id' => 4, 'user_id' => 3, 'store_id' => 1, 'shift_id' => 5, 'shift_date' => '2026-03-04', 'clock_in_time' => '2026-03-04 10:00:00']);
        $this->insert('timeclocks', ['id' => 5, 'user_id' => 2, 'store_id' => 1, 'shift_date' => '2026-03-05', 'clock_in_time' => '2026-03-05 10:00:00', 'clock_out_time' => '2026-03-05 09:00:00']);

        // Congés.
        $this->insert('timeoff_requests', ['id' => 1, 'user_id' => 3, 'store_id' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-03', 'type' => 'vacation',
            'status' => 'approved', 'reason' => 'Voyage', 'admin_note' => 'OK', 'processed_by' => 2, 'processed_at' => '2026-03-20 10:00:00', 'created_at' => '2026-03-10 08:00:00']);
        $this->insert('timeoff_requests', ['id' => 2, 'user_id' => 3, 'store_id' => 1, 'start_date' => '2026-05-01', 'end_date' => '2026-05-01', 'type' => 'weird',
            'status' => 'rejected', 'processed_by' => 99]);
        $this->insert('timeoff_requests', ['id' => 3, 'user_id' => 3, 'store_id' => 1, 'start_date' => '2026-06-05', 'end_date' => '2026-06-01', 'type' => 'sick', 'status' => 'pending']);
        $this->insert('timeoff_requests', ['id' => 4, 'user_id' => 99, 'store_id' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-02', 'type' => 'sick', 'status' => 'pending']);
        $this->insert('timeoff_requests', ['id' => 5, 'user_id' => 3, 'store_id' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-02', 'type' => 'sick', 'status' => 'bizarre']);

        // Candidatures aux quarts ouverts.
        $this->insert('shift_claims', ['id' => 1, 'shift_id' => 3, 'user_id' => 3, 'store_id' => 1, 'status' => 'pending', 'note' => 'Dispo', 'claimed_at' => '2026-03-03 12:00:00',
            'created_at' => '2026-03-03 12:00:00']);
        $this->insert('shift_claims', ['id' => 2, 'shift_id' => 3, 'user_id' => 3, 'store_id' => 1, 'status' => 'pending', 'claimed_at' => '2026-03-03 13:00:00']);
        $this->insert('shift_claims', ['id' => 3, 'shift_id' => 5, 'user_id' => 3, 'store_id' => 1, 'status' => 'pending']);
        $this->insert('shift_claims', ['id' => 4, 'shift_id' => 3, 'user_id' => 2, 'store_id' => 1, 'status' => 'refused', 'resolved_at' => '2026-03-04 09:00:00', 'resolved_by' => 99]);

        // Échanges de quarts.
        $this->insert('shift_swap_requests', ['id' => 1, 'store_id' => 1, 'requester_id' => 3, 'target_user_id' => 2, 'requester_shift_id' => 1, 'target_shift_id' => 2, 'status' => 'approved',
            'reason' => 'Rendez-vous', 'accepted_by_id' => 2, 'accepted_at' => '2026-03-01 10:00:00', 'approved_by_id' => 2, 'approved_at' => '2026-03-01 11:00:00',
            'created_at' => '2026-03-01 09:00:00']);
        $this->insert('shift_swap_requests', ['id' => 2, 'store_id' => 1, 'requester_id' => 3, 'requester_shift_id' => 1, 'status' => 'pending']);
        $this->insert('shift_swap_requests', ['id' => 3, 'store_id' => 1, 'requester_id' => 3, 'target_user_id' => 2, 'requester_shift_id' => 1, 'target_shift_id' => 5, 'status' => 'pending']);
        $this->insert('shift_swap_requests', ['id' => 4, 'store_id' => 1, 'requester_id' => 3, 'target_user_id' => 2, 'requester_shift_id' => 2, 'target_shift_id' => 1, 'status' => 'accepted',
            'accepted_by_id' => 2, 'accepted_at' => '2026-03-02 10:00:00', 'approved_by_id' => 99]);

        // Rapports d'embauche.
        $this->insert('hiring_reports', ['id' => 1, 'store_id' => 1, 'user_id' => 3, 'employee_number' => 'E003', 'employee_name' => 'Eve Employee', 'furigana_last_name' => 'エンプロイー',
            'furigana_first_name' => 'イブ', 'gender' => 'F', 'birth_date' => '1995-02-03', 'hire_date' => '2026-01-15', 'postal_code' => '100-0001', 'address' => 'Tokyo', 'email' => 'eve@example.test',
            'hired_by' => 'Max', 'notes' => 'RAS', 'created_by' => 2, 'created_at' => '2026-01-10 09:00:00', 'updated_at' => '2026-01-11 09:00:00']);
        $this->insert('hiring_reports', ['id' => 2, 'store_id' => 1, 'user_id' => 99, 'employee_name' => 'Inconnu', 'birth_date' => 'pas une date', 'hire_date' => '2026-02-30', 'created_by' => 99]);
        $this->insert('hiring_reports', ['id' => 3, 'store_id' => 99, 'employee_name' => 'Orphelin']);

        // Rapports de démission.
        $this->insert('resignation_reports', ['id' => 1, 'store_id' => 1, 'user_id' => 4, 'employee_number' => 'E004', 'employee_name' => 'Bob Broken', 'resignation_date' => '2026-05-31',
            'reason' => 'Déménagement', 'resignation_notice' => 'Préavis de 15 jours', 'person_in_charge' => 'Max', 'created_by' => 2, 'created_at' => '2026-05-01 09:00:00']);
        $this->insert('resignation_reports', ['id' => 2, 'store_id' => 1, 'user_id' => 99, 'resignation_date' => '', 'created_by' => 99]);
        $this->insert('resignation_reports', ['id' => 3, 'store_id' => 99]);

        // Rapports journaliers (heures locales Asia/Tokyo).
        $this->insert('daily_reports', ['id' => 1, 'store_id' => 1, 'author_id' => 3, 'report_date' => '2026-03-02', 'sales_total' => '123456.5', 'customer_count' => 42, 'labor_cost' => '30000',
            'waste_total' => '1500', 'notes' => 'Bonne journée', 'status' => 'validated', 'submitted_at' => '2026-03-02 21:00:00', 'validated_by' => 2, 'validated_at' => '2026-03-02 22:00:00',
            'mail_sent_at' => '2026-03-02 22:01:00', 'created_at' => '2026-03-02 20:00:00', 'updated_at' => '2026-03-02 22:01:00']);
        $this->insert('daily_reports', ['id' => 2, 'store_id' => 1, 'author_id' => 99, 'report_date' => '2026-03-03', 'sales_total' => 'beaucoup', 'customer_count' => '', 'status' => 'draft', 'validated_by' => 99]);
        $this->insert('daily_reports', ['id' => 3, 'store_id' => 1, 'author_id' => 3, 'report_date' => '2026-03-02', 'status' => 'draft']);
        $this->insert('daily_reports', ['id' => 4, 'store_id' => 1, 'author_id' => 3, 'report_date' => '2026-03-04', 'status' => 'archived']);
        $this->insert('daily_reports', ['id' => 5, 'store_id' => 1, 'author_id' => 3, 'report_date' => '2026-03-05', 'status' => 'draft', 'deleted_at' => '2026-03-06 10:00:00']);
        $this->insert('daily_reports', ['id' => 6, 'store_id' => 99, 'author_id' => 3, 'report_date' => '2026-03-02', 'status' => 'draft']);
        $this->insert('daily_reports', ['id' => 7, 'store_id' => 2, 'author_id' => 3, 'report_date' => 'hier', 'status' => 'draft']);
        $this->insert('daily_reports', ['id' => 8, 'store_id' => 2, 'author_id' => 3, 'report_date' => '2026-03-02', 'status' => 'submitted', 'submitted_at' => '2026-03-02 18:00:00']);

        // Réglages du rapport journalier : JSON de stores.daily_report_settings.
        $this->pdo->exec('UPDATE "stores" SET "daily_report_settings" = '.$this->pdo->quote(json_encode([
            'auto_send_on_validate' => true, 'auto_validate_time' => '02:30', 'reminder_time' => '16:00', 'no_report_time' => 'bientôt',
            'show_columns' => ['employee', 'bogus', 'net_hours'], 'cumulative_mode' => 'cumulative_input', 'mail_recipient_ids' => [2, 99, 3],
            'can_submit_roles' => ['manager'],
        ])).' WHERE "id" = 1');
        $this->pdo->exec('UPDATE "stores" SET "daily_report_settings" = \'{pas du json\' WHERE "id" = 2');

        // Fiches de paie (montants au centime, à retrouver à l'identique).
        $this->insert('salary_reports', ['id' => 1, 'store_id' => 1, 'user_id' => null, 'target_month' => '2026-03', 'person_in_charge' => 'Max Manager', 'total_payment' => '1234567.89',
            'total_deductions' => '200000.5', 'income_tax_base' => '1000000.25', 'withholding_tax' => '12345.67', 'residence_tax' => '5000', 'other_deductions' => '100.25',
            'net_payment' => '1034467.14', 'active_employees' => 3, 'hand_delivered_salary' => '50000', 'bank_transfer_salary' => '984467.14', 'staff_man_hours' => '160.5',
            'staff_total_payment' => '1200000', 'staff_avg_hourly_wage' => '1250.75', 'employee_work_hours' => '[{"user_id":3,"minutes":480},{"user_id":2,"minutes":90}]',
            'new_hires' => 1, 'resigned_staff' => 0, 'hire_registrations' => 'Eve', 'remarks' => 'RAS', 'created_by' => 2, 'created_at' => '2026-03-31 18:00:00', 'updated_at' => '2026-04-01 09:00:00']);
        $this->insert('salary_reports', ['id' => 2, 'store_id' => 1, 'user_id' => 3, 'target_month' => '2026-03', 'person_in_charge' => 'Inconnu Total', 'total_payment' => '300000',
            'employee_work_hours' => 'Eve Employee: 7.5h', 'created_by' => 99]);
        $this->insert('salary_reports', ['id' => 3, 'store_id' => 1, 'user_id' => null, 'target_month' => '2026-03', 'total_payment' => '1']);
        $this->insert('salary_reports', ['id' => 4, 'store_id' => 1, 'user_id' => null, 'target_month' => '2026-13', 'total_payment' => '1']);
        $this->insert('salary_reports', ['id' => 5, 'store_id' => 99, 'user_id' => null, 'target_month' => '2026-03']);
        $this->insert('salary_reports', ['id' => 6, 'store_id' => 1, 'user_id' => 99, 'target_month' => '2026-03']);
        $this->insert('salary_reports', ['id' => 7, 'store_id' => 2, 'user_id' => null, 'target_month' => '2026-03', 'person_in_charge' => '', 'employee_work_hours' => '[{"user_id":99,"minutes":10}]']);
        $this->insert('salary_reports', ['id' => 8, 'store_id' => 1, 'user_id' => null, 'target_month' => '2026-04', 'total_payment' => 'beaucoup']);
        $this->insert('salary_reports', ['id' => 9, 'store_id' => 1, 'user_id' => 2, 'target_month' => '2026-03', 'employee_work_hours' => '{"3":480}']);
        $this->insert('salary_reports', ['id' => 10, 'store_id' => 1, 'user_id' => 3, 'target_month' => '2026-03', 'total_payment' => '2']);

        // Retenues par magasin (un réglage par magasin).
        $this->insert('store_deduction_settings', ['id' => 1, 'store_id' => 1, 'enabled' => 1, 'health_insurance_rate' => '5.5', 'pension_rate' => '9.15', 'employment_insurance_rate' => '0.6',
            'income_tax_rate' => '3.2', 'resident_tax_monthly' => '1500', 'created_at' => '2026-03-01 09:00:00', 'updated_at' => '2026-03-02 09:00:00']);
        $this->insert('store_deduction_settings', ['id' => 2, 'store_id' => 2, 'enabled' => 0, 'health_insurance_rate' => '4.99', 'pension_rate' => '9.15', 'employment_insurance_rate' => '0.60',
            'income_tax_rate' => '0', 'resident_tax_monthly' => '0']);
        $this->insert('store_deduction_settings', ['id' => 3, 'store_id' => 99, 'enabled' => 1, 'health_insurance_rate' => '1', 'pension_rate' => '1', 'employment_insurance_rate' => '1',
            'income_tax_rate' => '1', 'resident_tax_monthly' => '1']);

        // Employés soumis aux retenues : colonne de store_user.
        $this->pdo->exec('UPDATE "store_user" SET "subject_to_deductions" = 1 WHERE "id" IN (1, 2, 4)');
        $this->pdo->exec('UPDATE "store_user" SET "subject_to_deductions" = 0 WHERE "id" = 3');
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
