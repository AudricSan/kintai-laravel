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

    /** Photos de magasin : mêmes limites que `PhotoStorage` du bundle (fichier de 10 Mo, 40 millions de pixels). */
    private const PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    private const PHOTO_MAX_PIXELS = 40_000_000;

    private const ORPHAN = 'ligne orpheline (référence absente de la source), ignorée';

    private \DateTimeZone $zone;

    /** @var array<int, true> */
    private array $users = [];

    /** @var array<string, list<int>> nom d'affichage (tel qu'importé) => id des utilisateurs importés qui le portent */
    private array $userNames = [];

    /** @var array<int, true> */
    private array $stores = [];

    /** @var array<int, true> */
    private array $shiftTypes = [];

    /** @var array<int, int> id de quart importé => son ical_sequence */
    private array $shiftSequences = [];

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
            $this->timeclocks($report);
            $this->timeoffRequests($report);
            $this->shiftClaims($report);
            $this->shiftSwapRequests($report);
            $this->hiringReports($report);
            $this->resignationReports($report);
            $this->dailyReports($report);
            $this->dailyReportSettings($report);
            $this->salaryReports($report);
            $this->salaryReportSettings($report);
            $this->salaryReportMembers($report);
            $this->teamDirectory($report);
            $this->messaging($report);
            $this->storePhotos($report);
            $this->googleCalendar($report);
            $this->planningImport($report);
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
            $this->userNames[$name][] = $id;
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
            $this->shiftSequences[$id] = max(0, (int) $shift['ical_sequence']);
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

    // ─── Bundles : pointage, congés, reprises et échanges de quarts ───────────

    /**
     * Vrai si la table du bundle existe dans la source et la cible ; sinon l'étape est sautée avec une note.
     * `$into` : nom de la table cible quand il diffère de celui de la source.
     */
    private function bundleStep(Report $report, string $table, ?string $into = null): bool
    {
        $into ??= $table;
        if (! $this->source->hasTable($table)) {
            $report->note("{$table} : absente de la source (bundle jamais installé), reprise sautée");

            return false;
        }
        if (! $this->target->tableExists($into)) {
            $count = $this->source->count($table);
            $report->tables[$into] = ['lues' => $count, 'importees' => 0, 'ignorees' => $count];
            $report->note("{$into} : table absente de la cible (bundle non migré), {$count} ligne(s) non reprise(s) ; activez le bundle, migrez, puis recommencez sur une base vide");

            return false;
        }

        return true;
    }

    /** @param  array<string, string>  $map  ancien statut => statut de la cible */
    private function status(mixed $value, array $map): ?string
    {
        $text = strtolower((string) $this->str($value));

        return $map[$text] ?? null;
    }

    private function user(mixed $id): ?int
    {
        return $id !== null && isset($this->users[(int) $id]) ? (int) $id : null;
    }

    private function timeclocks(Report $report): void
    {
        if (! $this->bundleStep($report, 'timeclocks')) {
            return;
        }
        $unlinked = 0;
        $badOut = 0;
        foreach ($this->source->rows('timeclocks', Source::OPTIONAL['timeclocks']) as $row) {
            $report->read('timeclocks');
            $id = (int) $row['id'];
            if (! isset($this->users[(int) $row['user_id']], $this->stores[(int) $row['store_id']])) {
                $report->skipped('timeclocks', self::ORPHAN);

                continue;
            }
            $in = $this->localDateTime($row['clock_in_time']);
            if ($in === null) {
                $report->skipped('timeclocks', "pointage {$id} sans heure d'entrée valable, ignoré");

                continue;
            }
            $out = $this->localDateTime($row['clock_out_time']);
            if ($out !== null && $out < $in) {
                $out = null;
                $badOut++;
            }
            $duration = is_numeric($row['duration_minutes']) && (int) $row['duration_minutes'] >= 0
                ? (int) $row['duration_minutes']
                : ($out === null ? null : intdiv($out->getTimestamp() - $in->getTimestamp(), 60));
            $shiftId = $row['shift_id'] !== null && isset($this->shiftSequences[(int) $row['shift_id']]) ? (int) $row['shift_id'] : null;
            if ($row['shift_id'] !== null && $shiftId === null) {
                $unlinked++;
            }
            $this->target->insert('timeclocks', [
                'id' => $id,
                'user_id' => (int) $row['user_id'],
                'store_id' => (int) $row['store_id'],
                'shift_id' => $shiftId,
                'shift_date' => $this->date($row['shift_date']) ?? $in->format('Y-m-d'),
                'clock_in_at' => $this->utc($row['clock_in_time']),
                'clock_out_at' => $out === null ? null : $this->utc($row['clock_out_time']),
                'duration_minutes' => $duration,
                'clock_in_ip' => $this->str($row['clock_in_ip'], 45),
                'clock_out_ip' => $this->str($row['clock_out_ip'], 45),
                'notes' => $this->str($row['notes'], 65535),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('timeclocks');
        }
        if ($unlinked > 0) {
            $report->warn("timeclocks : {$unlinked} pointage(s) dont le quart n'a pas été repris, lien vers le quart retiré");
        }
        if ($badOut > 0) {
            $report->warn("timeclocks : {$badOut} pointage(s) dont la sortie est illisible ou antérieure à l'entrée, repris sans sortie");
        }
    }

    private function timeoffRequests(Report $report): void
    {
        if (! $this->bundleStep($report, 'timeoff_requests')) {
            return;
        }
        $statuses = ['pending' => 'pending', 'approved' => 'approved', 'refused' => 'refused', 'rejected' => 'refused', 'denied' => 'refused',
            'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'withdrawn' => 'cancelled'];
        $types = ['vacation', 'sick', 'personal', 'unpaid', 'other'];
        $otherTypes = 0;
        foreach ($this->source->rows('timeoff_requests', Source::OPTIONAL['timeoff_requests']) as $row) {
            $report->read('timeoff_requests');
            $id = (int) $row['id'];
            if (! isset($this->users[(int) $row['user_id']], $this->stores[(int) $row['store_id']])) {
                $report->skipped('timeoff_requests', self::ORPHAN);

                continue;
            }
            $start = $this->date($row['start_date']);
            $end = $this->date($row['end_date']);
            if ($start === null || $end === null || $end < $start) {
                $report->skipped('timeoff_requests', "demande de congé {$id} aux dates illisibles ou dont la fin précède le début, ignorée");

                continue;
            }
            $status = $this->status($row['status'], $statuses);
            if ($status === null) {
                $report->skipped('timeoff_requests', "demande de congé {$id} de statut « {$row['status']} » inconnu, ignorée");

                continue;
            }
            $type = strtolower((string) $this->str($row['type']));
            if (! in_array($type, $types, true)) {
                $type = 'other';
                $otherTypes++;
            }
            $this->target->insert('timeoff_requests', [
                'id' => $id,
                'user_id' => (int) $row['user_id'],
                'store_id' => (int) $row['store_id'],
                'start_date' => $start,
                'end_date' => $end,
                'type' => $type,
                'status' => $status,
                'reason' => $this->str($row['reason'], 65535),
                'admin_note' => $this->str($row['admin_note'], 65535),
                'processed_by' => $this->user($row['processed_by']),
                'processed_at' => $this->utc($row['processed_at']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('timeoff_requests');
        }
        if ($otherTypes > 0) {
            $report->warn("timeoff_requests : {$otherTypes} demande(s) de type inconnu reprise(s) comme « other »");
        }
    }

    private function shiftClaims(Report $report): void
    {
        if (! $this->bundleStep($report, 'shift_claims')) {
            return;
        }
        $statuses = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected', 'refused' => 'rejected', 'denied' => 'rejected',
            'withdrawn' => 'withdrawn', 'cancelled' => 'withdrawn', 'canceled' => 'withdrawn'];
        $seen = [];
        foreach ($this->source->rows('shift_claims', Source::OPTIONAL['shift_claims']) as $row) {
            $report->read('shift_claims');
            $id = (int) $row['id'];
            if (! isset($this->users[(int) $row['user_id']], $this->stores[(int) $row['store_id']], $this->shiftSequences[(int) $row['shift_id']])) {
                $report->skipped('shift_claims', self::ORPHAN);

                continue;
            }
            $key = (int) $row['shift_id'].'|'.(int) $row['user_id'];
            if (isset($seen[$key])) {
                $report->skipped('shift_claims', "candidature {$id} en double (même quart, même employé), ignorée");

                continue;
            }
            $status = $this->status($row['status'], $statuses);
            if ($status === null) {
                $report->skipped('shift_claims', "candidature {$id} de statut « {$row['status']} » inconnu, ignorée");

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('shift_claims', [
                'id' => $id,
                'shift_id' => (int) $row['shift_id'],
                'user_id' => (int) $row['user_id'],
                'store_id' => (int) $row['store_id'],
                'status' => $status,
                'note' => $this->str($row['note'], 65535),
                'resolved_at' => $this->utc($row['resolved_at']),
                'resolved_by' => $this->user($row['resolved_by']),
                'created_at' => $this->utc($row['created_at']) ?? $this->utc($row['claimed_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('shift_claims');
        }
    }

    private function shiftSwapRequests(Report $report): void
    {
        if (! $this->bundleStep($report, 'shift_swap_requests')) {
            return;
        }
        // Dans la cible « accepted » = échange approuvé et appliqué ; le « oui » du collègue est `accepted_at`, statut pending.
        $statuses = ['pending' => 'pending', 'accepted' => 'pending', 'approved' => 'accepted', 'refused' => 'refused', 'rejected' => 'refused',
            'denied' => 'refused', 'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'withdrawn' => 'cancelled'];
        $lostAcceptor = 0;
        foreach ($this->source->rows('shift_swap_requests', Source::OPTIONAL['shift_swap_requests']) as $row) {
            $report->read('shift_swap_requests');
            $id = (int) $row['id'];
            if (! isset($this->stores[(int) $row['store_id']], $this->users[(int) $row['requester_id']], $this->shiftSequences[(int) $row['requester_shift_id']])) {
                $report->skipped('shift_swap_requests', self::ORPHAN);

                continue;
            }
            if ($row['target_user_id'] === null || $row['target_shift_id'] === null) {
                $report->skipped('shift_swap_requests', "échange {$id} sans collègue ou sans quart cible (la cible les exige), ignoré");

                continue;
            }
            if (! isset($this->users[(int) $row['target_user_id']], $this->shiftSequences[(int) $row['target_shift_id']])) {
                $report->skipped('shift_swap_requests', self::ORPHAN);

                continue;
            }
            $status = $this->status($row['status'], $statuses);
            if ($status === null) {
                $report->skipped('shift_swap_requests', "échange {$id} de statut « {$row['status']} » inconnu, ignoré");

                continue;
            }
            if ($row['accepted_by_id'] !== null) {
                $lostAcceptor++;
            }
            $this->target->insert('shift_swap_requests', [
                'id' => $id,
                'store_id' => (int) $row['store_id'],
                'requester_id' => (int) $row['requester_id'],
                'target_user_id' => (int) $row['target_user_id'],
                'requester_shift_id' => (int) $row['requester_shift_id'],
                'target_shift_id' => (int) $row['target_shift_id'],
                'requester_shift_sequence' => $this->shiftSequences[(int) $row['requester_shift_id']],
                'target_shift_sequence' => $this->shiftSequences[(int) $row['target_shift_id']],
                'status' => $status,
                'reason' => $this->str($row['reason'], 65535),
                'accepted_at' => $this->utc($row['accepted_at']),
                'approved_by_id' => $this->user($row['approved_by_id']),
                'approved_at' => $this->utc($row['approved_at']),
                'applied_requester_shift_id' => null,
                'applied_target_shift_id' => null,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('shift_swap_requests');
        }
        if ($lostAcceptor > 0) {
            $report->note("shift_swap_requests : accepted_by_id n'existe plus dans la cible ({$lostAcceptor} valeur(s) perdue(s))");
        }
    }

    // ─── Bundles : rapports d'embauche, de démission et journaliers ───────────

    private function hiringReports(Report $report): void
    {
        if (! $this->bundleStep($report, 'hiring_reports')) {
            return;
        }
        foreach ($this->source->rows('hiring_reports', Source::OPTIONAL['hiring_reports']) as $row) {
            $report->read('hiring_reports');
            if (! isset($this->stores[(int) $row['store_id']])) {
                $report->skipped('hiring_reports', self::ORPHAN);

                continue;
            }
            $this->target->insert('hiring_reports', [
                'id' => (int) $row['id'],
                'store_id' => (int) $row['store_id'],
                'user_id' => $this->user($row['user_id']),
                'employee_number' => $this->str($row['employee_number'], 255),
                'employee_name' => $this->str($row['employee_name'], 255),
                'furigana_last_name' => $this->str($row['furigana_last_name'], 255),
                'furigana_first_name' => $this->str($row['furigana_first_name'], 255),
                'gender' => $this->str($row['gender'], 255),
                'tax_classification' => $this->str($row['tax_classification'], 255),
                'birth_date' => $this->date($row['birth_date']),
                'hire_date' => $this->date($row['hire_date']),
                'education' => $this->str($row['education'], 255),
                'postal_code' => $this->str($row['postal_code'], 255),
                'address' => $this->str($row['address'], 65535),
                'phone' => $this->str($row['phone'], 255),
                'mobile_phone' => $this->str($row['mobile_phone'], 255),
                'email' => $this->str($row['email'], 255),
                'guarantor_name' => $this->str($row['guarantor_name'], 255),
                'guarantor_phone' => $this->str($row['guarantor_phone'], 255),
                'store_name' => $this->str($row['store_name'], 255),
                'hired_by' => $this->str($row['hired_by'], 255),
                'notes' => $this->str($row['notes'], 65535),
                'created_by' => $this->user($row['created_by']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('hiring_reports');
        }
    }

    private function resignationReports(Report $report): void
    {
        if (! $this->bundleStep($report, 'resignation_reports')) {
            return;
        }
        foreach ($this->source->rows('resignation_reports', Source::OPTIONAL['resignation_reports']) as $row) {
            $report->read('resignation_reports');
            if (! isset($this->stores[(int) $row['store_id']])) {
                $report->skipped('resignation_reports', self::ORPHAN);

                continue;
            }
            $this->target->insert('resignation_reports', [
                'id' => (int) $row['id'],
                'store_id' => (int) $row['store_id'],
                'user_id' => $this->user($row['user_id']),
                'employee_number' => $this->str($row['employee_number'], 255),
                'employee_name' => $this->str($row['employee_name'], 255),
                'resignation_date' => $this->date($row['resignation_date']),
                'reason' => $this->str($row['reason'], 65535),
                'resignation_notice' => $this->str($row['resignation_notice'], 65535),
                'notes' => $this->str($row['notes'], 65535),
                'person_in_charge' => $this->str($row['person_in_charge'], 255),
                'deactivated_at' => null,
                'created_by' => $this->user($row['created_by']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('resignation_reports');
        }
    }

    private function dailyReports(Report $report): void
    {
        if (! $this->bundleStep($report, 'daily_reports')) {
            return;
        }
        $statuses = ['draft' => 'draft', 'submitted' => 'submitted', 'validated' => 'validated', 'approved' => 'validated'];
        $seen = [];
        $deleted = 0;
        $unreadable = 0;
        foreach ($this->source->rows('daily_reports', Source::OPTIONAL['daily_reports']) as $row) {
            $report->read('daily_reports');
            $id = (int) $row['id'];
            if (! isset($this->stores[(int) $row['store_id']])) {
                $report->skipped('daily_reports', self::ORPHAN);

                continue;
            }
            // La cible n'a pas de corbeille pour les rapports : un rapport supprimé n'est pas repris.
            if ($this->str($row['deleted_at']) !== null) {
                $deleted++;
                $report->skipped('daily_reports', "rapport {$id} supprimé dans l'ancienne version, non repris");

                continue;
            }
            $date = $this->date($row['report_date']);
            if ($date === null) {
                $report->skipped('daily_reports', "rapport {$id} à la date illisible, ignoré");

                continue;
            }
            $key = (int) $row['store_id'].'|'.$date;
            if (isset($seen[$key])) {
                $report->skipped('daily_reports', "rapport {$id} en double (même magasin, même jour), ignoré");

                continue;
            }
            $status = $this->status($row['status'], $statuses);
            if ($status === null) {
                $report->skipped('daily_reports', "rapport {$id} de statut « {$row['status']} » inconnu, ignoré");

                continue;
            }
            $seen[$key] = true;
            foreach (['sales_total', 'labor_cost', 'waste_total', 'customer_count'] as $field) {
                if ($row[$field] !== null && $row[$field] !== '' && ! is_numeric($row[$field])) {
                    $unreadable++;
                }
            }
            $this->target->insert('daily_reports', [
                'id' => $id,
                'store_id' => (int) $row['store_id'],
                'author_id' => $this->user($row['author_id']),
                'report_date' => $date,
                'sales_total' => $this->decimal($row['sales_total']) ?? '0.00',
                'customer_count' => is_numeric($row['customer_count']) ? max(0, (int) $row['customer_count']) : 0,
                'labor_cost' => $this->decimal($row['labor_cost']) ?? '0.00',
                'waste_total' => $this->decimal($row['waste_total']) ?? '0.00',
                'notes' => $this->str($row['notes'], 65535),
                'status' => $status,
                'submitted_at' => $this->utc($row['submitted_at']),
                'validated_by' => $this->user($row['validated_by']),
                'validated_at' => $this->utc($row['validated_at']),
                'mail_sent_at' => $this->utc($row['mail_sent_at']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('daily_reports');
        }
        if ($deleted > 0) {
            $report->note("daily_reports : {$deleted} rapport(s) supprimé(s) (deleted_at) non repris, la cible n'a pas de corbeille");
        }
        if ($unreadable > 0) {
            $report->warn("daily_reports : {$unreadable} montant(s) illisible(s) repris comme 0");
        }
    }

    /** Réglages du rapport journalier : JSON de `stores.daily_report_settings`, un par magasin. */
    private function dailyReportSettings(Report $report): void
    {
        if (! $this->source->hasColumn('stores', 'daily_report_settings')) {
            return;
        }
        $rows = [];
        foreach ($this->source->rows('stores', ['id', 'daily_report_settings']) as $row) {
            if ($this->str($row['daily_report_settings']) !== null) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            return;
        }
        if (! $this->target->tableExists('daily_report_settings')) {
            $report->tables['daily_report_settings'] = ['lues' => count($rows), 'importees' => 0, 'ignorees' => count($rows)];
            $report->note('daily_report_settings : table absente de la cible (bundle non migré), '.count($rows).' réglage(s) non repris ; activez le bundle, migrez, puis recommencez sur une base vide');

            return;
        }
        $known = ['auto_send_on_validate', 'auto_validate_time', 'reminder_time', 'no_report_time', 'show_columns', 'cumulative_mode', 'mail_recipient_ids'];
        $columns = ['employee', 'type', 'schedule', 'gross_hours', 'pause', 'net_hours'];
        $clock = function (mixed $value, ?string $default): ?string {
            $time = $this->time($value);

            return $time === null ? $default : substr($time, 0, 5);
        };
        $lostKeys = 0;
        foreach ($rows as $row) {
            $report->read('daily_report_settings');
            $settings = json_decode((string) $row['daily_report_settings'], true);
            if (! is_array($settings) || array_is_list($settings) || array_intersect(array_keys($settings), $known) === []) {
                $report->skipped('daily_report_settings', 'réglages du magasin '.$row['id'].' illisibles ou sans réglage connu, valeurs par défaut de la cible');

                continue;
            }
            $lostKeys += count(array_diff(array_keys($settings), $known));
            $show = is_array($settings['show_columns'] ?? null) ? array_values(array_intersect($columns, $settings['show_columns'])) : [];
            $recipients = [];
            foreach (is_array($settings['mail_recipient_ids'] ?? null) ? $settings['mail_recipient_ids'] : [] as $recipient) {
                if (is_scalar($recipient) && $this->user($recipient) !== null && count($recipients) < 20) {
                    $recipients[(int) $recipient] = (int) $recipient;
                }
            }
            $mode = strtolower((string) $this->str($settings['cumulative_mode'] ?? null));
            $this->target->insert('daily_report_settings', [
                'store_id' => (int) $row['id'],
                'auto_send_on_validate' => $this->flag($settings['auto_send_on_validate'] ?? null, false),
                'auto_validate_time' => $clock($settings['auto_validate_time'] ?? null, null),
                'reminder_time' => $clock($settings['reminder_time'] ?? null, '15:00'),
                'no_report_time' => $clock($settings['no_report_time'] ?? null, '18:00'),
                'show_columns' => $show === [] ? null : json_encode($show),
                'cumulative_mode' => in_array($mode, ['per_day', 'cumulative_system', 'cumulative_input'], true) ? $mode : 'per_day',
                'mail_recipient_ids' => $recipients === [] ? null : json_encode(array_values($recipients)),
            ]);
            $report->imported('daily_report_settings');
        }
        if ($lostKeys > 0) {
            $report->note("daily_report_settings : {$lostKeys} clé(s) de réglage sans équivalent dans la cible (ex. anciens can_*_roles) non reprise(s)");
        }
    }

    // ─── Bundle : fiches de paie ──────────────────────────────────────────────

    /**
     * Fiches de paie. Données de paie : aucune valeur n'est recalculée ni corrigée ; ce qui ne se convertit pas de façon
     * certaine est écarté (champ perdu, ou ligne ignorée) avec un avertissement.
     */
    private function salaryReports(Report $report): void
    {
        if (! $this->bundleStep($report, 'salary_reports')) {
            return;
        }
        $amounts = ['total_payment', 'total_deductions', 'income_tax_base', 'withholding_tax', 'residence_tax', 'other_deductions',
            'net_payment', 'hand_delivered_salary', 'bank_transfer_salary', 'staff_man_hours', 'staff_total_payment', 'staff_avg_hourly_wage'];
        $counts = ['active_employees', 'new_hires', 'resigned_staff'];
        $seen = [];
        $ambiguous = 0;
        $unknown = 0;
        $lostHours = 0;
        foreach ($this->source->rows('salary_reports', Source::OPTIONAL['salary_reports']) as $row) {
            $report->read('salary_reports');
            $id = (int) $row['id'];
            if (! isset($this->stores[(int) $row['store_id']])) {
                $report->skipped('salary_reports', self::ORPHAN);

                continue;
            }
            $month = $this->str($row['target_month']);
            if ($month === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
                $report->skipped('salary_reports', "fiche {$id} au mois « {$row['target_month']} » illisible (AAAA-MM attendu), ignorée");

                continue;
            }
            // Une fiche individuelle dont l'employé n'existe plus n'est jamais reprise comme fiche « du magasin ».
            $individual = $row['user_id'] !== null && $row['user_id'] !== '';
            $userId = $individual ? $this->user($row['user_id']) : null;
            if ($individual && $userId === null) {
                $report->skipped('salary_reports', "fiche {$id} d'un employé absent de la source, ignorée (elle ne devient pas une fiche du magasin)");

                continue;
            }
            $unreadable = array_values(array_filter(array_merge($amounts, $counts), fn (string $field): bool => $row[$field] !== null && $row[$field] !== ''
                && (! is_numeric($row[$field]) || (in_array($field, $counts, true) && (float) $row[$field] < 0))));
            if ($unreadable !== []) {
                $report->skipped('salary_reports', "fiche {$id} au champ « {$unreadable[0]} » illisible, ignorée");

                continue;
            }
            $key = (int) $row['store_id'].'|'.$month.'|'.($userId ?? '');
            if (isset($seen[$key])) {
                $report->skipped('salary_reports', "fiche {$id} en double (même magasin, même mois, même employé), ignorée");

                continue;
            }
            $seen[$key] = true;

            $charge = $this->str($row['person_in_charge'], 255);
            $chargeId = null;
            if ($charge !== null) {
                $matches = $this->userNames[$charge] ?? [];
                if (count($matches) === 1) {
                    $chargeId = $matches[0];
                } elseif ($matches === []) {
                    $unknown++;
                } else {
                    $ambiguous++;
                }
            }
            $hours = $this->str($row['employee_work_hours']);
            $employeeHours = $hours === null ? null : $this->employeeHours($hours);
            if ($hours !== null && $employeeHours === null) {
                $lostHours++;
            }

            $values = [];
            foreach ($amounts as $field) {
                $values[$field] = $this->decimal($row[$field]) ?? '0.00';
            }
            foreach ($counts as $field) {
                $values[$field] = is_numeric($row[$field]) ? (int) $row[$field] : 0;
            }
            // `deduction_lines` (instantané des retenues personnalisées) est nouveau dans la cible : laissé à sa valeur nulle.
            $this->target->insert('salary_reports', [
                'id' => $id,
                'store_id' => (int) $row['store_id'],
                'user_id' => $userId,
                'target_month' => $month,
                'person_in_charge_id' => $chargeId,
                'employee_hours' => $employeeHours,
                'hire_registrations' => $this->str($row['hire_registrations'], 65535),
                'remarks' => $this->str($row['remarks'], 65535),
                'created_by' => $this->user($row['created_by']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ] + $values);
            $report->imported('salary_reports');
        }
        if ($unknown > 0) {
            $report->warn("salary_reports : {$unknown} responsable(s) (person_in_charge) sans utilisateur du même nom, valeur perdue (null)");
        }
        if ($ambiguous > 0) {
            $report->warn("salary_reports : {$ambiguous} responsable(s) (person_in_charge) dont le nom est porté par plusieurs utilisateurs, valeur perdue (null)");
        }
        if ($lostHours > 0) {
            $report->warn("salary_reports : {$lostHours} champ(s) employee_work_hours (texte libre ou forme inconnue) non repris, la cible stocke des minutes par employé");
        }
    }

    /**
     * Ancien `employee_work_hours` (texte libre) : repris seulement s'il s'agit d'un JSON valide de la forme lue par la
     * cible, `[{user_id, minutes}]`, employés importés et minutes entières positives ; jamais reconstruit.
     */
    private function employeeHours(string $text): ?string
    {
        $data = json_decode($text, true);
        if (! is_array($data) || $data === [] || ! array_is_list($data)) {
            return null;
        }
        $hours = [];
        foreach ($data as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['user_id', 'minutes']
                || ! is_int($entry['user_id']) || ! isset($this->users[$entry['user_id']])
                || ! is_int($entry['minutes']) || $entry['minutes'] < 0) {
                return null;
            }
            $hours[] = ['user_id' => $entry['user_id'], 'minutes' => $entry['minutes']];
        }

        return json_encode($hours);
    }

    /** Retenues d'un magasin : `store_deduction_settings` (une ligne par magasin), taux repris tels quels. */
    private function salaryReportSettings(Report $report): void
    {
        if (! $this->bundleStep($report, 'store_deduction_settings', 'salary_report_settings')) {
            return;
        }
        $rates = ['health_insurance_rate', 'pension_rate', 'employment_insurance_rate', 'income_tax_rate', 'resident_tax_monthly'];
        foreach ($this->source->rows('store_deduction_settings', Source::OPTIONAL['store_deduction_settings']) as $row) {
            $report->read('salary_report_settings');
            if (! isset($this->stores[(int) $row['store_id']])) {
                $report->skipped('salary_report_settings', self::ORPHAN);

                continue;
            }
            $values = [];
            foreach ($rates as $field) {
                $values[$field] = $this->decimal($row[$field]);
                if ($values[$field] === null) {
                    $report->skipped('salary_report_settings', 'réglage du magasin '.$row['store_id']." au taux « {$field} » illisible, ignoré (valeurs par défaut de la cible)");

                    continue 2;
                }
            }
            $this->target->insert('salary_report_settings', [
                'store_id' => (int) $row['store_id'],
                'deductions_enabled' => $this->flag($row['enabled'], false),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ] + $values);
            $report->imported('salary_report_settings');
        }
    }

    /** Employés soumis aux retenues : `store_user.subject_to_deductions` ; « non soumis » est l'absence de ligne dans la cible. */
    private function salaryReportMembers(Report $report): void
    {
        if (! $this->source->hasColumn('store_user', 'subject_to_deductions')) {
            return;
        }
        $rows = [];
        foreach ($this->source->rows('store_user', ['id', 'store_id', 'user_id', 'subject_to_deductions', 'created_at', 'updated_at']) as $row) {
            $value = strtolower((string) $this->str($row['subject_to_deductions']));
            if ((is_numeric($value) && (int) $value !== 0) || in_array($value, ['true', 'on', 'yes'], true)) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            return;
        }
        if (! $this->target->tableExists('salary_report_members')) {
            $report->tables['salary_report_members'] = ['lues' => count($rows), 'importees' => 0, 'ignorees' => count($rows)];
            $report->note('salary_report_members : table absente de la cible (bundle non migré), '.count($rows).' employé(s) soumis aux retenues non repris ; activez le bundle, migrez, puis recommencez sur une base vide');

            return;
        }
        $seen = [];
        foreach ($rows as $row) {
            $report->read('salary_report_members');
            $key = (int) $row['store_id'].'|'.(int) $row['user_id'];
            if (! isset($this->stores[(int) $row['store_id']], $this->users[(int) $row['user_id']]) || isset($seen[$key])) {
                $report->skipped('salary_report_members', self::ORPHAN);

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('salary_report_members', [
                'store_id' => (int) $row['store_id'],
                'user_id' => (int) $row['user_id'],
                'subject_to_deductions' => 1,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('salary_report_members');
        }
    }

    // ─── Bundle : annuaire ────────────────────────────────────────────────────

    /**
     * Annuaire : colonnes facultatives de `users` (`Source::DIRECTORY_COLUMNS`), une ligne `team_directory_profiles` seulement si
     * un champ est rempli ou si l'employé a demandé à ne pas figurer (sans ligne, l'employé est visible). Une valeur trop longue
     * pour la cible est ignorée (jamais tronquée).
     */
    private function teamDirectory(Report $report): void
    {
        $present = array_values(array_filter(Source::DIRECTORY_COLUMNS, fn (string $column): bool => $this->source->hasColumn('users', $column)));
        if ($present === []) {
            return;
        }
        $limits = ['bio' => 65535, 'skills' => 500, 'languages_spoken' => 255, 'hobbies' => 500];
        $rows = [];
        foreach ($this->source->rows('users', array_merge(['id', 'created_at', 'updated_at'], $present)) as $row) {
            if (! isset($this->users[(int) $row['id']])) {
                continue;
            }
            $hidden = in_array('show_in_directory', $present, true) && $this->flag($row['show_in_directory'], true) === 0;
            $filled = false;
            foreach (array_keys($limits) as $column) {
                $filled = $filled || ($this->str($row[$column] ?? null) !== null);
            }
            if ($hidden || $filled) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            return;
        }
        if (! $this->target->tableExists('team_directory_profiles')) {
            $report->tables['team_directory_profiles'] = ['lues' => count($rows), 'importees' => 0, 'ignorees' => count($rows)];
            $report->note('team_directory_profiles : table absente de la cible (bundle non migré), '.count($rows).' profil(s) non repris ; activez le bundle, migrez, puis recommencez sur une base vide');

            return;
        }
        foreach ($rows as $row) {
            $report->read('team_directory_profiles');
            $id = (int) $row['id'];
            $values = [];
            foreach ($limits as $column => $max) {
                $text = $this->str($row[$column] ?? null);
                if ($text !== null && mb_strlen($text) > $max) {
                    $report->warn("team_directory_profiles : champ « {$column} » de l'utilisateur {$id} trop long pour la cible ({$max} caractères au plus), non repris");
                    $text = null;
                }
                $values[$column] = $text;
            }
            $show = $this->flag($row['show_in_directory'] ?? null, true);
            if ($show === 1 && array_filter($values, static fn (?string $value): bool => $value !== null) === []) {
                $report->skipped('team_directory_profiles', "profil de l'utilisateur {$id} sans aucun champ repris, ignoré");

                continue;
            }
            $this->target->insert('team_directory_profiles', ['user_id' => $id] + $values + [
                'show_in_directory' => $show,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported('team_directory_profiles');
        }
    }

    // ─── Bundle : messagerie ──────────────────────────────────────────────────

    /**
     * Messagerie : fils, participants et messages, identifiants conservés. Les trois tables sont reprises ensemble (les
     * participants et les messages dépendent des fils). Rien n'est inventé : un fil sans sujet reprend un sujet vide (la cible
     * l'accepte), un fil sans participant ni message est repris tel quel, le créateur n'est pas ajouté aux participants.
     */
    private function messaging(Report $report): void
    {
        $ready = true;
        foreach (['message_threads', 'thread_participants', 'thread_messages'] as $table) {
            $ready = $this->bundleStep($report, $table) && $ready;
        }
        if (! $ready) {
            return;
        }

        $threads = [];
        foreach ($this->source->rows('message_threads', Source::OPTIONAL['message_threads']) as $row) {
            $report->read('message_threads');
            $id = (int) $row['id'];
            if (! isset($this->stores[(int) $row['store_id']], $this->users[(int) $row['creator_id']])) {
                $report->skipped('message_threads', self::ORPHAN);

                continue;
            }
            $subject = $this->str($row['subject']) ?? '';
            if (mb_strlen($subject) > 255) {
                $report->skipped('message_threads', "fil {$id} au sujet trop long pour la cible (255 caractères au plus), ignoré");

                continue;
            }
            $this->target->insert('message_threads', [
                'id' => $id,
                'store_id' => (int) $row['store_id'],
                'creator_id' => (int) $row['creator_id'],
                'subject' => $subject,
                'last_message_at' => $this->utc($row['last_message_at']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $threads[$id] = true;
            $report->imported('message_threads');
        }

        $seen = [];
        foreach ($this->source->rows('thread_participants', Source::OPTIONAL['thread_participants']) as $row) {
            $report->read('thread_participants');
            $key = (int) $row['thread_id'].'|'.(int) $row['user_id'];
            if (! isset($threads[(int) $row['thread_id']], $this->users[(int) $row['user_id']])) {
                $report->skipped('thread_participants', self::ORPHAN);

                continue;
            }
            if (isset($seen[$key])) {
                $report->skipped('thread_participants', 'participant en double (même fil, même employé), ignoré');

                continue;
            }
            $seen[$key] = true;
            $this->target->insert('thread_participants', [
                'id' => (int) $row['id'],
                'thread_id' => (int) $row['thread_id'],
                'user_id' => (int) $row['user_id'],
                'is_read' => $this->flag($row['is_read'], false),
                'last_read_at' => $this->utc($row['last_read_at']),
                'hidden_at' => null,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['created_at']),
            ]);
            $report->imported('thread_participants');
        }

        foreach ($this->source->rows('thread_messages', Source::OPTIONAL['thread_messages']) as $row) {
            $report->read('thread_messages');
            $id = (int) $row['id'];
            if (! isset($threads[(int) $row['thread_id']], $this->users[(int) $row['sender_id']])) {
                $report->skipped('thread_messages', self::ORPHAN);

                continue;
            }
            $sentAt = $this->utc($row['created_at']);
            if ($sentAt === null || ! is_string($row['body']) || trim($row['body']) === '') {
                $report->skipped('thread_messages', "message {$id} sans texte ou à la date illisible, ignoré");

                continue;
            }
            $this->target->insert('thread_messages', [
                'id' => $id,
                'thread_id' => (int) $row['thread_id'],
                'sender_id' => (int) $row['sender_id'],
                'body' => $row['body'],
                'erased_at' => null,
                'sent_at' => $sentAt,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);
            $report->imported('thread_messages');
        }
    }

    // ─── Bundle : photos de magasin ───────────────────────────────────────────

    /**
     * Photos de magasin : les fichiers sont copiés (jamais re-encodés) vers le dossier privé du bundle sous un nom généré ;
     * une image dont le fichier manque, est illisible ou n'est ni JPEG ni PNG n'est pas reprise (pas de ligne sans fichier) et
     * une soumission supprimée ou sans aucune image reprise est ignorée. Aucun fichier n'est copié en simulation.
     */
    private function storePhotos(Report $report): void
    {
        $ready = $this->bundleStep($report, 'store_photo_submissions');
        $ready = $this->bundleStep($report, 'store_photo_images') && $ready;
        if (! $ready) {
            return;
        }

        /** @var array<int, array<string, mixed>> $pending soumissions valables, insérées avec leur première image reprise */
        $pending = [];
        foreach ($this->source->rows('store_photo_submissions', Source::OPTIONAL['store_photo_submissions']) as $row) {
            $report->read('store_photo_submissions');
            $id = (int) $row['id'];
            if (! isset($this->stores[(int) $row['store_id']])) {
                $report->skipped('store_photo_submissions', self::ORPHAN);

                continue;
            }
            if ($this->str($row['deleted_at']) !== null) {
                $report->skipped('store_photo_submissions', "soumission {$id} supprimée dans l'ancienne version, non reprise");

                continue;
            }
            $pending[$id] = $row;
        }

        $inserted = [];
        foreach ($this->source->rows('store_photo_images', Source::OPTIONAL['store_photo_images'], 'submission_id') as $row) {
            $report->read('store_photo_images');
            $id = (int) $row['id'];
            $submissionId = (int) $row['submission_id'];
            if (! isset($pending[$submissionId])) {
                $report->skipped('store_photo_images', self::ORPHAN);

                continue;
            }
            $file = $this->copyPhoto($id, $row['filepath'], $report);
            if ($file === null) {
                $report->tables['store_photo_images']['ignorees']++;

                continue;
            }
            if (! isset($inserted[$submissionId])) {
                $submission = $pending[$submissionId];
                $this->target->insert('store_photo_submissions', [
                    'id' => $submissionId,
                    'store_id' => (int) $submission['store_id'],
                    'created_by' => $this->user($submission['created_by']),
                    'notes' => $this->str($submission['notes'], 65535),
                    'archived_at' => null,
                    'created_at' => $this->utc($submission['created_at']),
                    'updated_at' => $this->utc($submission['updated_at']),
                ]);
                $inserted[$submissionId] = true;
                $report->imported('store_photo_submissions');
            }
            $this->target->insert('store_photo_images', [
                'id' => $id,
                'submission_id' => $submissionId,
                'filename' => $this->str($row['filename'], 255) ?? basename(str_replace('\\', '/', (string) $row['filepath'])),
                'path' => $file['path'],
                'mime_type' => $file['mime'],
                'filesize' => $file['size'],
                'width' => $file['width'],
                'height' => $file['height'],
                'sort_order' => max(0, (int) $row['sort_order']),
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['created_at']),
            ]);
            $report->imported('store_photo_images');
        }
        foreach (array_diff_key($pending, $inserted) as $id => $submission) {
            $report->skipped('store_photo_submissions', "soumission {$id} sans aucune image reprise, ignorée");
        }
    }

    /**
     * Copie une photo de la source (chemin relatif au dossier `uploads` de la source, avec les mêmes protections que les avatars)
     * vers `store-photos/<32 hexadécimaux>.jpg|png` du disque privé de la cible. Null, avec un avertissement, si elle est refusée.
     *
     * @return array{path: string, mime: string, size: int, width: int, height: int}|null
     */
    private function copyPhoto(int $imageId, mixed $path, Report $report): ?array
    {
        $name = $this->str($path, 512);
        $reject = function (string $why) use ($report, $imageId, $name): ?array {
            $report->warn("store_photo_images : fichier « {$name} » de l'image {$imageId} non repris ({$why})");

            return null;
        };
        if ($name === null) {
            return $reject('chemin vide');
        }
        $relative = str_replace('\\', '/', $name);
        if (str_contains($relative, "\0") || preg_match('#^(/|[A-Za-z]:)#', $relative) === 1 || in_array('..', explode('/', $relative), true)) {
            return $reject('chemin refusé');
        }
        $root = $this->source->uploadsDir === null ? false : realpath($this->source->uploadsDir);
        if ($root === false) {
            return $reject('dossier des fichiers de la source introuvable');
        }
        $real = false;
        // Le chemin enregistré est relatif à `uploads/`, avec ou sans ce préfixe.
        foreach ([$relative, preg_replace('#^(storage/)?uploads/#', '', $relative) ?? $relative] as $candidate) {
            $file = $root.DIRECTORY_SEPARATOR.$candidate;
            if (is_file($file) && ! is_link($file)) {
                $real = realpath($file);
                break;
            }
        }
        if ($real === false) {
            return $reject('fichier absent');
        }
        if (! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return $reject('chemin refusé');
        }
        $size = filesize($real);
        $info = $size === false || $size < 1 || $size > self::PHOTO_MAX_BYTES ? false : @getimagesize($real);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::PHOTO_MAX_PIXELS) {
            return $reject('ce n\'est pas une image acceptable');
        }
        [$extension, $mime] = match ($info[2]) {
            IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
            IMAGETYPE_PNG => ['png', 'image/png'],
            default => [null, null],
        };
        if ($extension === null) {
            return $reject('seuls JPEG et PNG sont repris');
        }

        $generated = bin2hex(random_bytes(16)).'.'.$extension;
        if (! $this->dryRun) {
            $directory = $this->target->storageDir.'/store-photos';
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new MigrationException("Impossible de créer le dossier des photos de la cible : {$directory}");
            }
            $destination = $directory.'/'.$generated;
            if (! @copy($real, $destination)) {
                throw new MigrationException("Impossible de copier la photo vers {$destination}");
            }
            $this->copiedFiles[] = $destination;
        }

        return ['path' => $generated, 'mime' => $mime, 'size' => (int) $size, 'width' => $info[0], 'height' => $info[1]];
    }

    // ─── Bundle : calendrier Google ───────────────────────────────────────────

    /**
     * Calendrier Google : le jeton de rafraîchissement legacy (chiffré par l'ancien `SecretBox` avec le secret d'instance) est
     * déchiffré puis rechiffré avec l'APP_KEY de la cible. Une liaison dont le jeton est illisible (altéré, autre secret, secret
     * absent, format inconnu) n'est pas reprise : l'utilisateur reconnecte son agenda. Ni le jeton ni le secret ne figurent
     * jamais dans le rapport. Liens et file d'attente : seulement ceux des liaisons reprises ; `source_id` devient `source_key`
     * (`shift` : id du quart ; `timeoff` : `timeoff-<id>`, l'identifiant stable que lit le bundle).
     */
    private function googleCalendar(Report $report): void
    {
        $ready = $this->bundleStep($report, 'calendar_connections', 'google_calendar_connections');
        $ready = $this->bundleStep($report, 'calendar_event_links', 'google_calendar_event_links') && $ready;
        $ready = $this->bundleStep($report, 'calendar_sync_queue', 'google_calendar_sync_queue') && $ready;
        if (! $ready) {
            return;
        }
        $connectionsTable = 'google_calendar_connections';
        $encrypter = null;
        if ($this->source->count('calendar_connections') > 0) {
            if ($this->target->appKey === null) {
                throw new MigrationException('APP_KEY absente du .env de la cible : les jetons Google ne peuvent pas être rechiffrés (php artisan key:generate, puis recommencez).');
            }
            $encrypter = new AppEncrypter($this->target->appKey);
        }
        $secret = $this->source->instanceSecret();

        $connections = [];
        $byUser = [];
        $storeFallback = 0;
        $unknownStatus = 0;
        foreach ($this->source->rows('calendar_connections', Source::OPTIONAL['calendar_connections']) as $row) {
            $report->read($connectionsTable);
            $id = (int) $row['id'];
            $userId = (int) $row['user_id'];
            if (strtolower((string) $this->str($row['provider'])) !== 'google') {
                $report->skipped($connectionsTable, "liaison {$id} d'un autre fournisseur que Google, ignorée");

                continue;
            }
            if (! isset($this->users[$userId])) {
                $report->skipped($connectionsTable, self::ORPHAN);

                continue;
            }
            if (isset($byUser[$userId])) {
                $report->skipped($connectionsTable, "liaison {$id} en double (même employé), ignorée");

                continue;
            }
            $stored = is_string($row['refresh_token']) ? trim($row['refresh_token']) : '';
            $token = $stored === '' ? null : SecretBox::decrypt($stored, $secret);
            if ($token === null || $token === '' || $encrypter === null) {
                $why = $stored !== '' && SecretBox::isEncrypted($stored) && ! SecretBox::usableSecret($secret)
                    ? "secret d'instance absent ou trop court"
                    : 'jeton vide, altéré, chiffré avec un autre secret ou de format inconnu';
                $report->skipped($connectionsTable, "liaison {$id} (employé {$userId}) non reprise, jeton illisible ({$why}) : l'employé devra reconnecter son agenda Google");

                continue;
            }
            $email = $this->str($row['account_email']);
            $calendarId = $this->str($row['calendar_id']);
            $calendarName = $this->str($row['calendar_name']);
            foreach ([$email, $calendarId, $calendarName] as $text) {
                if ($text !== null && mb_strlen($text) > 255) {
                    $report->skipped($connectionsTable, "liaison {$id} : adresse, identifiant ou nom d'agenda trop long pour la cible (255 caractères au plus), ignorée");

                    continue 2;
                }
            }
            $status = strtolower((string) $this->str($row['status']));
            if (! in_array($status, ['active', 'revoked'], true)) {
                $status = 'revoked';
                $unknownStatus++;
            }
            $options = json_decode((string) $row['options'], true);
            $options = is_array($options) ? $options : [];
            $nameMode = 'instance';
            $storeId = null;
            if (($options['calendar_name_mode'] ?? null) === 'store' && (int) ($options['store_id'] ?? 0) > 0) {
                if (isset($this->stores[(int) $options['store_id']])) {
                    $nameMode = 'store';
                    $storeId = (int) $options['store_id'];
                } else {
                    $storeFallback++;
                }
            }
            $reconciled = isset($options['reconciled_at']) && is_numeric($options['reconciled_at']) && (int) $options['reconciled_at'] > 0
                ? gmdate('Y-m-d H:i:s', (int) $options['reconciled_at'])
                : null;

            $this->target->insert($connectionsTable, [
                'id' => $id,
                'user_id' => $userId,
                'account_email' => $email,
                'refresh_token' => $encrypter->encryptString($token),
                'calendar_id' => $calendarId,
                'calendar_name' => $calendarName,
                'status' => $status,
                'name_mode' => $nameMode,
                'store_id' => $storeId,
                'last_sync_at' => $this->utc($row['last_sync_at']),
                'last_error' => $this->errorText($row['last_error']),
                'reconciled_at' => $reconciled,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $connections[$id] = true;
            $byUser[$userId] = true;
            $report->imported($connectionsTable);
        }
        if ($storeFallback > 0) {
            $report->warn("{$connectionsTable} : {$storeFallback} liaison(s) nommée(s) d'après un magasin absent de la cible, nom d'agenda de l'organisation utilisé");
        }
        if ($unknownStatus > 0) {
            $report->warn("{$connectionsTable} : {$unknownStatus} liaison(s) au statut inconnu reprise(s) comme révoquées (l'employé doit reconnecter son agenda)");
        }

        $this->calendarSources($report, 'calendar_event_links', 'google_calendar_event_links', $connections, function (array $row): ?array {
            $hash = is_string($row['content_hash']) ? strtolower(trim($row['content_hash'])) : '';
            $event = $this->str($row['external_event_id']);
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || $event === null || mb_strlen($event) > 255) {
                return null;
            }

            return ['external_event_id' => $event, 'content_hash' => $hash, 'synced_at' => $this->utc($row['synced_at'])];
        });
        $this->calendarSources($report, 'calendar_sync_queue', 'google_calendar_sync_queue', $connections, fn (array $row): array => [
            'attempts' => is_numeric($row['attempts']) ? max(0, min(65535, (int) $row['attempts'])) : 0,
            'next_attempt_at' => $this->utc($row['next_attempt_at']),
            'last_error' => $this->errorText($row['last_error']),
            'created_at' => $this->utc($row['created_at']),
        ]);
    }

    /** Message d'erreur de synchronisation repris tel quel (jamais tronqué : au-delà de la colonne `text`, abandonné). */
    private function errorText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 65535 ? $value : null;
    }

    /**
     * Liens d'événements et file d'attente : mêmes règles (liaison reprise, type de source connu, une ligne par liaison, type et
     * source) ; `$columns` donne les colonnes propres à la table, ou null pour ignorer la ligne.
     *
     * @param  array<int, true>  $connections  liaisons reprises
     * @param  \Closure(array<string, mixed>): ?array<string, mixed>  $columns
     */
    private function calendarSources(Report $report, string $from, string $into, array $connections, \Closure $columns): void
    {
        $seen = [];
        foreach ($this->source->rows($from, Source::OPTIONAL[$from]) as $row) {
            $report->read($into);
            $connection = (int) $row['connection_id'];
            if (! isset($connections[$connection])) {
                $report->skipped($into, self::ORPHAN.' (liaison non reprise)');

                continue;
            }
            $type = strtolower((string) $this->str($row['source_type']));
            if (! in_array($type, ['shift', 'timeoff'], true)) {
                $report->skipped($into, "ligne {$row['id']} d'un type de source inconnu de la cible, ignorée");

                continue;
            }
            if (! is_numeric($row['source_id']) || (int) $row['source_id'] <= 0) {
                $report->skipped($into, "ligne {$row['id']} sans identifiant de source valable, ignorée");

                continue;
            }
            $key = $type === 'timeoff' ? 'timeoff-'.(int) $row['source_id'] : (string) (int) $row['source_id'];
            $unique = $connection.'|'.$type.'|'.$key;
            if (isset($seen[$unique])) {
                $report->skipped($into, "ligne {$row['id']} en double (même liaison et même source), ignorée");

                continue;
            }
            $extra = $columns($row);
            if ($extra === null) {
                $report->skipped($into, "ligne {$row['id']} à l'empreinte ou à l'identifiant d'événement illisible, ignorée");

                continue;
            }
            $seen[$unique] = true;
            $this->target->insert($into, ['id' => (int) $row['id'], 'connection_id' => $connection, 'source_type' => $type, 'source_key' => $key] + $extra);
            $report->imported($into);
        }
    }

    // ─── Bundle : import de planning ──────────────────────────────────────────

    /**
     * Import de planning : réglages de grille par magasin (`store_import_settings`) puis alias de noms (`import_aliases`).
     * Un réglage invalide n'est pas repris (les valeurs par défaut du bundle s'appliquent) : rien n'est inventé ni corrigé.
     * `stores.excel_import_settings` a disparu de l'ancienne version, seule la table compte.
     */
    private function planningImport(Report $report): void
    {
        if ($this->bundleStep($report, 'store_import_settings')) {
            $this->planningImportSettings($report);
        }
        if ($this->bundleStep($report, 'import_aliases')) {
            $this->planningImportAliases($report);
        }
    }

    private function planningImportSettings(Report $report): void
    {
        $table = 'store_import_settings';
        $numbers = ['col_start', 'col_end', 'base_hour', 'minutes_per_col', 'block_size', 'shift_rows', 'auto_pause_after_minutes', 'auto_pause_minutes'];
        $seen = [];
        foreach ($this->source->rows($table, Source::OPTIONAL[$table]) as $row) {
            $report->read($table);
            $storeId = (int) $row['store_id'];
            if (! isset($this->stores[$storeId])) {
                $report->skipped($table, self::ORPHAN);

                continue;
            }
            if (isset($seen[$storeId])) {
                $report->skipped($table, "réglage {$row['id']} en double (même magasin {$storeId}), ignoré");

                continue;
            }
            $values = [];
            foreach ($numbers as $field) {
                $text = is_scalar($row[$field]) ? trim((string) $row[$field]) : '';
                if (preg_match('/^\d+$/', $text) !== 1 || (int) $text > 65535) {
                    $report->skipped($table, "réglage du magasin {$storeId} : « {$field} » doit être un entier de 0 à 65535, réglage ignoré (valeurs par défaut du bundle)");

                    continue 2;
                }
                $values[$field] = (int) $text;
            }
            $pattern = is_string($row['sheet_filter_pattern']) ? $row['sheet_filter_pattern'] : '';
            // Même construction que le bundle (`GridSettings::regex`) : le motif est entouré de « ~ » et lu en UTF-8.
            if ($pattern === '' || mb_strlen($pattern) > 255 || @preg_match('~'.str_replace('~', '\~', $pattern).'~u', '') === false) {
                $report->skipped($table, "réglage du magasin {$storeId} : motif de feuille vide, de plus de 255 caractères ou qui n'est pas une expression régulière valable, réglage ignoré (valeurs par défaut du bundle)");

                continue;
            }
            $seen[$storeId] = true;
            $this->target->insert($table, ['store_id' => $storeId] + $values + [
                'sheet_filter_pattern' => $pattern,
                'created_at' => $this->utc($row['created_at']),
                'updated_at' => $this->utc($row['updated_at']),
            ]);
            $report->imported($table);
        }
    }

    /**
     * Alias : le nom est stocké en minuscules dans la cible (avertissement agrégé s'il change) ; la colonne s'appelle `staff_name`
     * (ou `alias_name` dans une base ancienne, `staff_name` préféré). Deux noms identiques après mise en minuscules : le plus
     * petit `id` est gardé.
     */
    private function planningImportAliases(Report $report): void
    {
        $table = 'import_aliases';
        $nameColumns = array_values(array_filter(Source::ONE_OF[$table], fn (string $column): bool => $this->source->hasColumn($table, $column)));
        $seen = [];
        $lowered = 0;
        foreach ($this->source->rows($table, array_merge(Source::OPTIONAL[$table], $nameColumns)) as $row) {
            $report->read($table);
            $storeId = (int) $row['store_id'];
            $userId = (int) $row['user_id'];
            if (! isset($this->stores[$storeId], $this->users[$userId])) {
                $report->skipped($table, self::ORPHAN);

                continue;
            }
            $name = null;
            foreach ($nameColumns as $column) {
                $name ??= $this->str($row[$column]);
            }
            if ($name === null || mb_strlen($name) > 255) {
                $report->skipped($table, "alias {$row['id']} au nom vide ou de plus de 255 caractères, ignoré");

                continue;
            }
            $key = mb_strtolower($name);
            if (mb_strlen($key) > 255) {
                $report->skipped($table, "alias {$row['id']} au nom de plus de 255 caractères, ignoré");

                continue;
            }
            if (isset($seen[$storeId.'|'.$key])) {
                $report->skipped($table, "alias {$row['id']} en double après mise en minuscules (même magasin et même nom), ignoré");

                continue;
            }
            $seen[$storeId.'|'.$key] = true;
            if ($key !== $name) {
                $lowered++;
            }
            $created = $this->utc($row['created_at']);
            $this->target->insert($table, [
                'id' => (int) $row['id'],
                'store_id' => $storeId,
                'staff_name' => $key,
                'user_id' => $userId,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            $report->imported($table);
        }
        if ($lowered > 0) {
            $report->warn("{$table} : {$lowered} nom(s) d'alias mis en minuscules (la cible les compare en minuscules)");
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
