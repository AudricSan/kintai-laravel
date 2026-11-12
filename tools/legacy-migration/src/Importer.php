<?php

declare(strict_types=1);

namespace KintaiLegacy;

/**
 * Migration à sens unique du Core : lit la source (lecture seule) et écrit la cible dans une seule transaction.
 * Les identifiants sont conservés (utilisateurs, magasins, types de quart, quarts) ; les rôles sont rapprochés par
 * `slug` avec ceux de la cible. Aucune dépendance vers `app/`.
 */
final class Importer
{
    /** Permissions du Core ; les autres clés de l'ancien catalogue appartiennent aux bundles (tranche ultérieure). */
    public const CORE_PERMISSIONS = [
        'employees.view', 'employees.create', 'employees.update', 'employees.delete',
        'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete', 'shifts.import',
        'stores.view', 'stores.create', 'stores.update', 'stores.delete',
        'payroll.view', 'payroll.generate', 'payroll.export',
    ];

    /**
     * Mots de passe connus comme trop faibles : l'ancien code attribuait `0000` aux comptes créés ou réinitialisés par
     * un gérant. Chaque essai coûte un calcul bcrypt par compte, la liste reste donc courte.
     */
    public const WEAK_PASSWORDS = ['0000', '1234', '12345678'];

    private const AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const AVATAR_MAX_BYTES = 10 * 1024 * 1024;

    private const ORPHAN = 'ligne orpheline (référence absente de la source), ignorée';

    private \DateTimeZone $zone;

    /** @var array<int, true> */
    private array $users = [];

    /** @var array<int, true> */
    private array $stores = [];

    /** @var array<int, true> */
    private array $shiftTypes = [];

    /** @var array<int, int> ancien id de rôle => id de rôle dans la cible */
    private array $roles = [];

    /** @var list<string> fichiers d'avatar copiés, supprimés si l'import échoue */
    private array $copiedFiles = [];

    public function __construct(
        private readonly Source $source,
        private readonly Target $target,
        private readonly string $timezone,
        private readonly bool $dryRun = false,
    ) {
        try {
            $this->zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new MigrationException("Fuseau horaire inconnu : {$timezone}");
        }
    }

    /** Fuseau de l'instance source : celui de ses réglages (`app_timezone`), à défaut celui qui est demandé. */
    public static function sourceTimezone(Source $source): ?string
    {
        return $source->setting('app_timezone');
    }

    public function run(): Report
    {
        $report = new Report;
        $report->dryRun = $this->dryRun;

        $this->source->assertSupportedSchema();
        $this->target->assertReady();
        $this->assertDistinctDatabases();

        $this->target->begin();
        try {
            $this->roles($report);
            $this->users($report);
            $this->stores($report);
            $this->storeUsers($report);
            $this->storeFeatures($report);
            $this->shiftTypes($report);
            $this->shifts($report);
            $this->availabilities($report);
            $this->roleAssignments($report);
            $this->settings($report);
            $this->icalTokens($report);

            $this->dryRun ? $this->target->rollback() : $this->target->commit();
        } catch (\Throwable $e) {
            $this->target->rollback();
            $this->removeCopiedFiles();
            if ($e instanceof MigrationException) {
                throw $e;
            }
            throw new MigrationException('Échec de la migration, la cible est inchangée : '.$e->getMessage(), 0, $e);
        }
        if ($this->dryRun) {
            $this->removeCopiedFiles();
        }

        return $report;
    }

    // ─── Rôles ────────────────────────────────────────────────────────────────

    private function roles(Report $report): void
    {
        $existing = [];
        foreach ($this->target->select('SELECT id, slug, is_system FROM roles') as $row) {
            $existing[(string) $row['slug']] = ['id' => (int) $row['id'], 'system' => (bool) $row['is_system']];
        }

        foreach ($this->source->rows('roles', ['id', 'name', 'slug', 'color', 'description', 'is_system', 'is_manager', 'created_at', 'updated_at']) as $role) {
            $report->read('roles');
            $slug = $this->str($role['slug'], 255);
            if ($slug === null) {
                $report->skipped('roles', 'rôle sans identifiant (slug) ignoré');

                continue;
            }
            if (isset($existing[$slug])) {
                $this->roles[(int) $role['id']] = $existing[$slug]['id'];
                if (! $existing[$slug]['system']) {
                    $this->target->run('DELETE FROM role_permissions WHERE role_id = ?', [$existing[$slug]['id']]);
                }
                $report->note("rôle « {$slug} » déjà présent dans la cible : réutilisé".($existing[$slug]['system'] ? '' : ', permissions remplacées par celles de la source'));
            } else {
                $this->target->insert('roles', [
                    'name' => $this->str($role['name'], 255) ?? $slug,
                    'slug' => $slug,
                    'description' => $this->str($role['description'], 255),
                    'color' => $this->color($role['color'], '#6B7280'),
                    'is_system' => $this->flag($role['is_system'], false),
                    'is_manager' => $this->flag($role['is_manager'], false),
                    'created_at' => $this->utc($role['created_at']),
                    'updated_at' => $this->utc($role['updated_at']),
                ]);
                $existing[$slug] = ['id' => (int) $this->target->pdo->lastInsertId(), 'system' => (bool) $this->flag($role['is_system'], false)];
                $this->roles[(int) $role['id']] = $existing[$slug]['id'];
                $report->imported('roles');
            }
        }

        $seenUnknown = [];
        $written = [];
        foreach ($this->source->rows('role_permissions', ['role_id', 'permission_key', 'scope'], 'role_id') as $permission) {
            $report->read('role_permissions');
            $oldRole = (int) $permission['role_id'];
            $key = (string) $permission['permission_key'];
            $newRole = $this->roles[$oldRole] ?? null;
            if ($newRole === null) {
                $report->skipped('role_permissions', self::ORPHAN);

                continue;
            }
            $systemTarget = false;
            foreach ($existing as $info) {
                if ($info['id'] === $newRole) {
                    $systemTarget = $info['system'];
                }
            }
            if ($systemTarget) {
                continue; // le rôle système (Owner) a tous les droits sans ligne
            }
            if (! in_array($key, self::CORE_PERMISSIONS, true)) {
                if (! isset($seenUnknown[$key])) {
                    $seenUnknown[$key] = true;
                    $report->warn("role_permissions : permission « {$key} » hors du Core (bundle ou inconnue), non reprise");
                }
                $report->tables['role_permissions']['ignorees']++;

                continue;
            }
            if (isset($written[$newRole.'|'.$key])) {
                continue;
            }
            $written[$newRole.'|'.$key] = true;
            $this->target->insert('role_permissions', [
                'role_id' => $newRole,
                'permission' => $key,
                'all_stores' => (string) $permission['scope'] === 'global' ? 1 : 0,
            ]);
            $report->imported('role_permissions');
        }
    }

    // ─── Identité ─────────────────────────────────────────────────────────────

    private function users(Report $report): void
    {
        $columns = Source::REQUIRED['users'];
        foreach ($this->source->rows('users', $columns) as $user) {
            $report->read('users');
            $id = (int) $user['id'];
            $email = $this->str($user['email'], 255);
            $first = (string) ($this->str($user['first_name'], 255) ?? '');
            $last = (string) ($this->str($user['last_name'], 255) ?? '');
            $name = $this->str($user['display_name'], 255) ?? ($this->str($first.' '.$last, 255) ?? $email ?? 'Utilisateur '.$id);

            [$password, $mustChange] = $this->password($user['password_hash'], $user['deleted_at'] !== null, $report, $id);
            $locale = $this->str($user['language'], 10);

            $this->target->insert('users', [
                'id' => $id,
                'name' => $name,
                'first_name' => $first,
                'last_name' => $last,
                'furigana_first_name' => $this->str($user['furigana_first_name'], 255),
                'furigana_last_name' => $this->str($user['furigana_last_name'], 255),
                'email' => $email,
                'employee_code' => $this->str($user['employee_code'], 255),
                'password' => $password,
                'locale' => $locale !== null && preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale) === 1 ? $locale : null,
                'color' => $this->color($user['color'], '#3B82F6'),
                'avatar_path' => $this->copyAvatar($id, $user['avatar_path'], $report),
                'is_active' => $this->flag($user['is_active'], true),
                'must_change_password' => $mustChange ? 1 : 0,
                'share_email' => $this->flag($user['share_email'], false),
                'share_phone' => $this->flag($user['share_phone'], false),
                'share_mobile_phone' => $this->flag($user['share_mobile_phone'], false),
                'created_at' => $this->utc($user['created_at']),
                'updated_at' => $this->utc($user['updated_at']),
                'deleted_at' => $this->utc($user['deleted_at']),
            ]);
            $this->users[$id] = true;
            $report->imported('users');

            $profile = [
                'gender' => $this->str($user['gender'], 255),
                'birth_date' => $this->date($user['birth_date']),
                'address' => $this->str($user['address'], 255),
                'postal_code' => $this->str($user['postal_code'], 255),
                'phone' => $this->str($user['phone'], 255),
                'mobile_phone' => $this->str($user['mobile_phone'], 255),
                'tax_classification' => $this->str($user['tax_classification'], 255),
                'education' => $this->str($user['education'], 255),
                'guarantor_name' => $this->str($user['guarantor_name'], 255),
                'guarantor_phone' => $this->str($user['guarantor_phone'], 255),
            ];
            if (array_filter($profile, static fn (mixed $value): bool => $value !== null) !== []) {
                $this->target->insert('employee_profiles', ['user_id' => $id] + $profile + [
                    'created_at' => $this->utc($user['created_at']),
                    'updated_at' => $this->utc($user['updated_at']),
                ]);
                $report->imported('employee_profiles');
            }
        }
    }

    /**
     * L'empreinte bcrypt de l'ancienne version est reprise telle quelle (le pilote bcrypt de Laravel la vérifie).
     * La longueur d'un mot de passe haché est inconnaissable : `must_change_password` est posé quand l'empreinte
     * correspond à un mot de passe faible connu (WEAK_PASSWORDS), comme le faisait l'ancien code pour `0000`.
     * Une empreinte absente ou illisible devient un mot de passe inutilisable, à réinitialiser.
     *
     * @return array{0: string, 1: bool}
     */
    private function password(mixed $hash, bool $deleted, Report $report, int $userId): array
    {
        $hash = is_string($hash) ? trim($hash) : '';
        if (preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash) !== 1) {
            if (! $deleted) {
                $report->warn("users : l'utilisateur {$userId} n'a pas d'empreinte bcrypt valable ; mot de passe inutilisable, à réinitialiser");
            }

            return [password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 10]), true];
        }
        if (! $deleted) {
            foreach (self::WEAK_PASSWORDS as $weak) {
                if (password_verify($weak, $hash)) {
                    return [$hash, true];
                }
            }
        }

        return [$hash, false];
    }

    private function copyAvatar(int $userId, mixed $path, Report $report): ?string
    {
        $name = $this->str($path, 255);
        if ($name === null) {
            return null;
        }
        $reject = function (string $why) use ($report, $userId, $name): ?string {
            $report->warn("users : avatar « {$name} » de l'utilisateur {$userId} non repris ({$why})");

            return null;
        };
        if ($name !== basename(str_replace('\\', '/', $name)) || str_contains($name, '..') || str_contains($name, "\0")) {
            return $reject('chemin refusé');
        }
        $uploads = $this->source->uploadsDir === null ? false : realpath($this->source->uploadsDir.'/avatars');
        if ($uploads === false) {
            return $reject('dossier des avatars de la source introuvable');
        }
        $file = $uploads.DIRECTORY_SEPARATOR.$name;
        $real = is_file($file) && ! is_link($file) ? realpath($file) : false;
        if ($real === false) {
            return $reject('fichier absent');
        }
        if (! str_starts_with($real, $uploads.DIRECTORY_SEPARATOR)) {
            return $reject('chemin refusé');
        }
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $size = filesize($real);
        if (! in_array($extension, self::AVATAR_EXTENSIONS, true) || $size === false || $size > self::AVATAR_MAX_BYTES || @getimagesize($real) === false) {
            return $reject('ce n\'est pas une image acceptable');
        }

        $relative = 'avatars/user_'.$userId.'_'.bin2hex(random_bytes(8)).'.'.$extension;
        if ($this->dryRun) {
            return $relative;
        }
        $directory = $this->target->storageDir.'/avatars';
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new MigrationException("Impossible de créer le dossier des avatars de la cible : {$directory}");
        }
        $destination = $this->target->storageDir.'/'.$relative;
        if (! @copy($real, $destination)) {
            throw new MigrationException("Impossible de copier l'avatar vers {$destination}");
        }
        $this->copiedFiles[] = $destination;

        return $relative;
    }

    private function removeCopiedFiles(): void
    {
        foreach ($this->copiedFiles as $file) {
            @unlink($file);
        }
        $this->copiedFiles = [];
    }

    // ─── Magasins ─────────────────────────────────────────────────────────────

    private function stores(Report $report): void
    {
        foreach ($this->source->rows('stores', Source::REQUIRED['stores']) as $store) {
            $report->read('stores');
            $code = $this->str($store['code'], 255);
            if ($code === null) {
                $report->skipped('stores', 'magasin '.$store['id'].' sans code, ignoré');

                continue;
            }
            $currency = strtoupper((string) $this->str($store['currency'], 3));
            $hour = static fn (mixed $value): ?int => is_numeric($value) && (int) $value >= 0 && (int) $value <= 23 ? (int) $value : null;
            $weekStart = (int) $store['week_start_day'];
            $locale = $this->str($store['locale'], 10);

            $this->target->insert('stores', [
                'id' => (int) $store['id'],
                'name' => $this->str($store['name'], 255) ?? $code,
                'code' => $code,
                'timezone' => $this->str($store['timezone'], 255) ?? 'UTC',
                'currency' => strlen($currency) === 3 ? $currency : 'EUR',
                'currency_symbol_style' => $this->str($store['currency_symbol_style'], 255) ?? 'kanji',
                'locale' => $locale,
                'address_street' => $this->str($store['address_street'], 255),
                'address_city' => $this->str($store['address_city'], 255),
                'address_postal' => $this->str($store['address_postal'], 255),
                'address_country' => $this->str($store['address_country'], 255),
                'phone' => $this->str($store['phone'], 255),
                'email' => $this->str($store['email'], 255),
                'is_active' => $this->flag($store['is_active'], true),
                'week_start_day' => $weekStart === 0 ? 7 : max(1, min(7, $weekStart)),
                'low_staff_hour_start' => $hour($store['low_staff_hour_start']),
                'low_staff_hour_end' => $hour($store['low_staff_hour_end']),
                'min_staff_per_day' => max(0, (int) $store['min_staff_per_day']),
                'min_shift_minutes' => max(0, (int) $store['min_shift_minutes']),
                'max_shift_minutes' => max(0, (int) $store['max_shift_minutes']),
                'created_at' => $this->utc($store['created_at']),
                'updated_at' => $this->utc($store['updated_at']),
                'deleted_at' => $this->utc($store['deleted_at']),
            ]);
            $this->stores[(int) $store['id']] = true;
            $report->imported('stores');
        }
    }

    private function storeUsers(Report $report): void
    {
        foreach ($this->source->rows('store_user', Source::REQUIRED['store_user']) as $row) {
            $report->read('store_user');
            if (! isset($this->stores[(int) $row['store_id']], $this->users[(int) $row['user_id']])) {
                $report->skipped('store_user', self::ORPHAN);

                continue;
            }
            $this->target->insert('store_user', [
                'store_id' => (int) $row['store_id'],
                'user_id' => (int) $row['user_id'],
                'staff_code' => $this->str($row['staff_code'], 255),
                'hire_date' => $this->date($row['hire_date']),
                'contract_type' => $this->str($row['contract_type'], 255),
                'is_active' => $this->flag($row['is_active'], true),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('store_user');
        }
    }

    private function storeFeatures(Report $report): void
    {
        $seen = [];
        foreach ($this->source->rows('store_features', ['store_id', 'feature_key', 'enabled'], 'store_id') as $row) {
            $report->read('store_features');
            $feature = $this->str($row['feature_key'], 255);
            $key = (int) $row['store_id'].'|'.$feature;
            if ($feature === null || ! isset($this->stores[(int) $row['store_id']]) || isset($seen[$key])) {
                $report->skipped('store_features', self::ORPHAN);

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('store_features', [
                'store_id' => (int) $row['store_id'],
                'feature' => $feature,
                'enabled' => $this->flag($row['enabled'], true),
            ]);
            $report->imported('store_features');
        }
    }

    // ─── Planning ─────────────────────────────────────────────────────────────

    private function shiftTypes(Report $report): void
    {
        foreach ($this->source->rows('shift_types', Source::REQUIRED['shift_types']) as $type) {
            $report->read('shift_types');
            $start = $this->time($type['start_time']);
            $end = $this->time($type['end_time']);
            if ($start === null || $end === null) {
                $report->skipped('shift_types', 'type de quart '.$type['id'].' sans horaires valables, ignoré');

                continue;
            }
            $this->target->insert('shift_types', [
                'id' => (int) $type['id'],
                'name' => $this->str($type['name'], 255) ?? (string) $type['code'],
                'code' => (string) $this->str($type['code'], 255),
                'starts_at' => $start,
                'ends_at' => $end,
                'hourly_rate' => $this->decimal($type['hourly_rate']) ?? '0.00',
                'color' => $this->color($type['color'], '#3B82F6'),
                'sort_order' => max(0, (int) $type['sort_order']),
                'is_active' => $this->flag($type['is_active'], true),
                'created_at' => $this->utc($type['created_at']),
                'updated_at' => $this->utc($type['updated_at']),
            ]);
            $this->shiftTypes[(int) $type['id']] = true;
            $report->imported('shift_types');
        }

        $seen = [];
        foreach ($this->source->rows('shift_type_stores', ['shift_type_id', 'store_id'], 'shift_type_id') as $row) {
            $report->read('shift_type_store');
            $key = $row['shift_type_id'].'|'.$row['store_id'];
            if (! isset($this->shiftTypes[(int) $row['shift_type_id']], $this->stores[(int) $row['store_id']]) || isset($seen[$key])) {
                $report->skipped('shift_type_store', self::ORPHAN);

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('shift_type_store', ['shift_type_id' => (int) $row['shift_type_id'], 'store_id' => (int) $row['store_id']]);
            $report->imported('shift_type_store');
        }

        $seen = [];
        foreach ($this->source->rows('user_shift_type_rates', Source::REQUIRED['user_shift_type_rates']) as $row) {
            $report->read('shift_type_user');
            $key = $row['user_id'].'|'.$row['shift_type_id'];
            $rate = $this->decimal($row['hourly_rate']);
            if ($rate === null || ! isset($this->users[(int) $row['user_id']], $this->shiftTypes[(int) $row['shift_type_id']]) || isset($seen[$key])) {
                $report->skipped('shift_type_user', self::ORPHAN);

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('shift_type_user', [
                'user_id' => (int) $row['user_id'],
                'shift_type_id' => (int) $row['shift_type_id'],
                'hourly_rate' => $rate,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('shift_type_user');
        }
    }

    private function shifts(Report $report): void
    {
        foreach ($this->source->rows('shifts', Source::REQUIRED['shifts']) as $shift) {
            $report->read('shifts');
            $id = (int) $shift['id'];
            if (! isset($this->stores[(int) $shift['store_id']])) {
                $report->skipped('shifts', self::ORPHAN);

                continue;
            }

            $open = $this->flag($shift['is_open'], false) === 1 || $shift['user_id'] === null;
            $userId = $open ? null : (int) $shift['user_id'];
            if ($userId !== null && ! isset($this->users[$userId])) {
                $report->skipped('shifts', "quart {$id} d'un employé absent de la source, ignoré");

                continue;
            }

            $startLocal = $this->localDateTime($shift['starts_at']) ?? $this->combine($shift['shift_date'], $shift['start_time']);
            $endLocal = $this->localDateTime($shift['ends_at']) ?? $this->combine($shift['shift_date'], $shift['end_time']);
            if ($startLocal !== null && $endLocal !== null && $endLocal <= $startLocal && ($this->flag($shift['cross_midnight'], false) === 1 || $this->localDateTime($shift['ends_at']) === null)) {
                $endLocal = $endLocal->modify('+1 day');
            }
            if ($startLocal === null || $endLocal === null || $endLocal <= $startLocal) {
                $report->skipped('shifts', "quart {$id} sans début et fin valables, ignoré");

                continue;
            }
            $date = $this->date($shift['shift_date']) ?? $startLocal->format('Y-m-d');
            $utc = static fn (\DateTimeImmutable $moment): string => $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $createdBy = $shift['created_by'] !== null && isset($this->users[(int) $shift['created_by']]) ? (int) $shift['created_by'] : null;
            $this->target->insert('shifts', [
                'id' => $id,
                'store_id' => (int) $shift['store_id'],
                'user_id' => $userId,
                'shift_type_id' => $shift['shift_type_id'] !== null && isset($this->shiftTypes[(int) $shift['shift_type_id']]) ? (int) $shift['shift_type_id'] : null,
                'shift_date' => $date,
                'starts_at' => $utc($startLocal),
                'ends_at' => $utc($endLocal),
                'pause_minutes' => max(0, min(65535, (int) $shift['pause_minutes'])),
                'notes' => $this->str($shift['notes'], 65535) ?? $this->str($shift['open_shift_note'], 65535),
                'hourly_rate_override' => $this->decimal($shift['hourly_rate_override']),
                'net_minutes_override' => $shift['net_minutes_override'] === null || ! is_numeric($shift['net_minutes_override']) ? null : max(0, (int) $shift['net_minutes_override']),
                'ical_uid' => self::uuid(),
                'ical_sequence' => max(0, (int) $shift['ical_sequence']),
                'created_by' => $createdBy,
                'created_at' => $this->utc($shift['created_at']),
                'updated_at' => $this->utc($shift['updated_at']),
                'deleted_at' => $this->utc($shift['deleted_at']),
            ]);
            $report->imported('shifts');
        }
    }

    private function availabilities(Report $report): void
    {
        $dayZero = 0;
        foreach ($this->source->rows('availabilities', Source::REQUIRED['availabilities']) as $row) {
            $report->read('availabilities');
            if (! isset($this->users[(int) $row['user_id']], $this->stores[(int) $row['store_id']])) {
                $report->skipped('availabilities', self::ORPHAN);

                continue;
            }
            $day = (int) $row['day_of_week'];
            if ($day === 0) {
                $day = 7; // l'API de l'ancienne version numérotait 0-6 (dimanche = 0), la cible 1 (lundi) à 7
                $dayZero++;
            }
            if ($day < 1 || $day > 7) {
                $report->skipped('availabilities', "jour de semaine {$row['day_of_week']} hors limites, ignoré");

                continue;
            }
            $this->target->insert('availabilities', [
                'user_id' => (int) $row['user_id'],
                'store_id' => (int) $row['store_id'],
                'day_of_week' => $day,
                'starts_at' => $this->time($row['start_time']),
                'ends_at' => $this->time($row['end_time']),
                'is_available' => $this->flag($row['is_available'], true),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('availabilities');
        }
        if ($dayZero > 0) {
            $report->warn("availabilities : {$dayZero} disponibilité(s) du jour 0 reprises comme dimanche (7)");
        }
    }

    // ─── Rôles assignés, réglages, jetons ─────────────────────────────────────

    private function roleAssignments(Report $report): void
    {
        $seen = [];
        foreach ($this->source->rows('role_assignments', Source::REQUIRED['role_assignments']) as $row) {
            $report->read('role_user');
            $role = $this->roles[(int) $row['role_id']] ?? null;
            $user = (int) $row['user_id'];
            $type = (string) $row['scope_type'];
            $store = null;
            if ($type === 'store') {
                $store = $row['scope_id'] === null ? null : (int) $row['scope_id'];
                if ($store === null || ! isset($this->stores[$store])) {
                    $report->skipped('role_user', 'assignation de portée magasin sans magasin valable, ignorée');

                    continue;
                }
            } elseif ($type !== 'global') {
                $report->skipped('role_user', "portée « {$type} » inconnue, assignation ignorée");

                continue;
            }
            if ($role === null || ! isset($this->users[$user])) {
                $report->skipped('role_user', self::ORPHAN);

                continue;
            }
            $key = $user.'|'.$role.'|'.($store ?? 'null');
            if (isset($seen[$key])) {
                $report->skipped('role_user', 'assignation en double ignorée');

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('role_user', [
                'user_id' => $user,
                'role_id' => $role,
                'store_id' => $store,
                'created_at' => $this->utc($row['created_at']) ?? gmdate('Y-m-d H:i:s'),
            ]);
            $report->imported('role_user');
        }
    }

    private function settings(Report $report): void
    {
        $map = [
            'app_subtitle' => ['organization.subtitle', 'string'],
            'app_login_notice' => ['organization.login_notice', 'string'],
            'app_support_email' => ['organization.support_email', 'string'],
            'app_public_url' => ['organization.public_url', 'string'],
            'app_theme_dark_mode' => ['theme.dark_mode', 'string'],
            'app_locale' => ['instance.locale', 'string'],
            'backup_auto_enabled' => ['backup.auto_enabled', 'bool'],
            'backup_max_keep' => ['backup.max_keep', 'int'],
        ];
        $values = [];
        foreach ($this->source->rows('app_settings', ['key', 'value'], 'key') as $row) {
            $report->read('app_settings');
            $old = (string) $row['key'];
            if (! isset($map[$old]) || $row['value'] === null || $row['value'] === '') {
                $report->tables['app_settings']['ignorees']++;

                continue;
            }
            [$key, $type] = $map[$old];
            $values[$key] = match ($type) {
                'bool' => in_array(strtolower((string) $row['value']), ['1', 'true', 'on', 'yes'], true),
                'int' => (int) $row['value'],
                default => (string) $row['value'],
            };
        }
        // Les horodatages ont été convertis depuis ce fuseau : la cible doit l'afficher de la même façon.
        $values['instance.timezone'] = $this->timezone;

        $now = gmdate('Y-m-d H:i:s');
        foreach ($values as $key => $value) {
            $this->target->run('DELETE FROM app_settings WHERE '.$this->target->quote('key').' = ?', [$key]);
            $this->target->insert('app_settings', ['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
            $report->imported('app_settings');
        }
        $report->note('réglages non repris : tout ce qui n\'est pas listé dans le README (mascotte, réglages des bundles…)');
    }

    /** Les anciens jetons iCal étaient stockés en clair : ils ne sont pas repris, chacun régénère son lien. */
    private function icalTokens(Report $report): void
    {
        $count = $this->source->count('ical_tokens');
        if ($count > 0) {
            $report->tables['ical_tokens'] = ['lues' => $count, 'importees' => 0, 'ignorees' => $count];
            $report->note("{$count} abonnement(s) iCal : les anciens liens ne fonctionneront plus, chaque employé doit régénérer son lien (la cible ne garde qu'une empreinte).");
        }
    }

    // ─── Conversions ──────────────────────────────────────────────────────────

    private function str(mixed $value, ?int $max = null): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $max === null ? $text : mb_substr($text, 0, $max);
    }

    private function flag(mixed $value, bool $default): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }

        return in_array(strtolower((string) $value), ['0', 'false', 'off', 'no'], true) ? 0 : 1;
    }

    private function color(mixed $value, string $default): string
    {
        $color = $this->str($value);

        return $color !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : $default;
    }

    private function date(mixed $value): ?string
    {
        $text = $this->str($value);
        if ($text === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return substr($text, 0, 10);
    }

    private function time(mixed $value): ?string
    {
        $text = $this->str($value);
        if ($text === null || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $text, $m) !== 1) {
            return null;
        }

        return sprintf('%02d:%s:%s', (int) $m[1], $m[2], $m[4] ?? '00');
    }

    private function decimal(mixed $value): ?string
    {
        return $value !== null && $value !== '' && is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /** Date-heure naïve de la source, dans le fuseau de l'instance. */
    private function localDateTime(mixed $value): ?\DateTimeImmutable
    {
        $text = $this->str($value);
        if ($text === null || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $text) !== 1) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat(strlen($text) === 16 ? 'Y-m-d?H:i' : 'Y-m-d?H:i:s', $text, $this->zone);

        return $moment === false ? null : $moment;
    }

    private function combine(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        $day = $this->date($date);
        $clock = $this->time($time);

        return $day === null || $clock === null ? null : $this->localDateTime($day.' '.$clock);
    }

    /** Date-heure de la source (fuseau de l'instance) convertie en UTC pour la cible. */
    private function utc(mixed $value): ?string
    {
        $moment = $this->localDateTime($value);

        return $moment?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** La cible ne doit pas être la source (même fichier SQLite). */
    private function assertDistinctDatabases(): void
    {
        $file = $this->source->file;
        if ($file === null || $this->target->driver !== 'sqlite') {
            return;
        }
        foreach ($this->target->select('PRAGMA database_list') as $database) {
            // Seulement pour SQLite (la requête échoue ou est vide ailleurs).
            if (isset($database['file']) && $database['file'] !== '' && realpath((string) $database['file']) === $file) {
                throw new MigrationException('La source et la cible sont le même fichier SQLite.');
            }
        }
    }
}
