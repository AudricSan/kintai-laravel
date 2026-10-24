<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\Scheduling;

use kintai\UI\Controller\Web\HasAdminAccess;

use kintai\Core\Repositories\ImportAliasRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\UI\ViewRenderer;
use kintai\Core\Services\ExcelShiftImport\ExcelShiftImportService;
use kintai\Core\Services\ShiftWageCalculator;

final class AdminShiftImportController
{
    use HasAdminAccess;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly AuditLogger $auditLogger,
        private readonly ImportAliasRepositoryInterface $importAliases,
        private readonly RoleAssignmentSyncService $roleSync,
    ) {}

    public function importShifts(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        return Response::html($this->view->render('scheduling.shifts-import', [
            'title'      => 'Importer des shifts Excel',
            'all_stores' => $this->availableStores($managedIds),
            'error'      => $request->query('error'),
        ], 'layout.app'));
    }

    public function processImport(Request $request): Response
    {
        $storeId = (int) $request->post('store_id', 0);
        $this->assertStoreAccess($request, $storeId);

        $file = $_FILES['excel_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return Response::redirect($this->base() . '/admin/shifts/import?error=upload');
        }

        if ($file['size'] > 20 * 1024 * 1024) {
            return Response::redirect($this->base() . '/admin/shifts/import?error=upload');
        }

        $allowedExtensions = ['xlsx', 'xls', 'xlsm', 'csv', 'ods'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            return Response::redirect($this->base() . '/admin/shifts/import?error=upload');
        }

        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'text/csv',
            'application/vnd.oasis.opendocument.spreadsheet',
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
            return Response::redirect($this->base() . '/admin/shifts/import?error=upload');
        }

        $uploadDir = BASE_PATH . '/storage/uploads/' . $storeId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = basename($file['name']);
        $safeName     = preg_replace('/[\p{C}]/u', '', $originalName);
        $ext          = pathinfo($safeName, PATHINFO_EXTENSION);
        $baseName     = $ext !== '' ? substr($safeName, 0, -(strlen($ext) + 1)) : $safeName;

        // Heure comprise dans le nom : deux imports du même fichier le même jour s'écrasaient (audit n°8). Suffixe
        // numéroté si deux envois tombent dans la même seconde.
        $suffix     = $ext !== '' ? '.' . $ext : '';
        $backupName = sprintf('Upload_%s-File(%s)%s', date('Y_m_d_His'), $baseName, $suffix);
        for ($n = 2; file_exists($uploadDir . $backupName); $n++) {
            $backupName = sprintf('Upload_%s-File(%s)-%d%s', date('Y_m_d_His'), $baseName, $n, $suffix);
        }
        $backupPath = $uploadDir . $backupName;

        $filePath = move_uploaded_file($file['tmp_name'], $backupPath) ? $backupPath : $file['tmp_name'];
        $this->pruneStoreUploads($uploadDir, 5);

        try {
            $settings = $this->stores->getImportSettings($storeId);
            $service  = new ExcelShiftImportService($settings);
            $entries  = $service->parse($filePath, $file['name']);
        } catch (\Throwable) {
            return Response::redirect($this->base() . '/admin/shifts/import?error=parse');
        }

        if (empty($entries)) {
            return Response::redirect($this->base() . '/admin/shifts/import?error=empty');
        }

        $entries  = $this->mergeAdjacentEntries($entries);
        $allUsers = $this->users->findAll();

        $storeMembers = array_values(array_filter(
            $allUsers,
            fn($u) => in_array((int) $u['id'], $this->storeMemberIds($storeId), true)
        ));

        $aliases = $this->importAliases->findByStore($storeId);
        $entries = $this->resolveEntryUserIds($entries, $storeMembers, $aliases);

        foreach ($entries as &$e) {
            $e['db_exact_match']    = false;
            $e['db_overlap']        = false;
            $e['db_overlap_shifts'] = [];
            $e['db_duplicate']      = false;

            $uid   = (int) ($e['user_id'] ?? 0);
            $date  = $e['date'] ?? '';
            $start = $e['start_time'] ?? '00:00';
            $end   = $e['end_time']   ?? '00:00';
            $cross = ($start !== $end && strtotime($end) !== false && strtotime($start) !== false && strtotime($end) <= strtotime($start)) ? 1 : 0;

            if ($uid <= 0 || $date === '') continue;

            foreach ($this->shifts->findByUserAndDate($uid, $storeId, $date) as $ex) {
                if (!empty($ex['deleted_at'])) continue;
                $exCross = (int) ($ex['cross_midnight'] ?? 0);
                if (($ex['start_time'] ?? '') === $start && ($ex['end_time'] ?? '') === $end) {
                    $e['db_exact_match'] = true;
                } elseif ($this->timeSlotsOverlap($date, $start, $end, $cross, $ex['shift_date'], $ex['start_time'] ?? '', $ex['end_time'] ?? '', $exCross)) {
                    $e['db_overlap'] = true;
                    $e['db_overlap_shifts'][] = [
                        'start_time' => $ex['start_time'] ?? '',
                        'end_time'   => $ex['end_time']   ?? '',
                    ];
                }
            }
            $e['db_duplicate'] = $e['db_exact_match'] || $e['db_overlap'];
        }
        unset($e);

        $importMonths = array_unique(array_filter(array_map(fn($e) => substr($e['date'] ?? '', 0, 7), $entries)));
        $importMonth = count($importMonths) === 1 ? reset($importMonths) : '';

        return Response::html($this->view->render('scheduling.shifts-import-preview', [
            'title'        => 'Prévisualisation — Import shifts',
            'entries'      => $entries,
            'store_id'     => $storeId,
            'all_users'    => $allUsers,
            'import_month' => $importMonth,
            // Modale de création rapide : sélecteur de rôle dynamique (table roles)
            'assignable_roles'      => $this->roleSync->assignableStoreRoles(),
            'default_store_role_id' => (int) ($this->roleSync->defaultStoreRole()['id'] ?? 0),
        ], 'layout.app'));
    }

    public function confirmImport(Request $request): Response
    {
        $storeId = (int) $request->post('store_id', 0);
        $this->assertStoreAccess($request, $storeId);

        $shiftsJson = $request->post('shifts_json', '');
        $shifts     = is_string($shiftsJson) ? (json_decode($shiftsJson, true) ?: []) : [];
        if (empty($shifts)) {
            return Response::redirect($this->base() . '/admin/shifts?success=imported&count=0');
        }

        $cfg         = $this->stores->getImportSettings($storeId);
        $pauseAfter  = (int) ($cfg['auto_pause_after_minutes'] ?? 0);
        $pauseDur    = (int) ($cfg['auto_pause_minutes']       ?? 30);
        $shiftTypes  = $this->shiftTypes->findActive($storeId);
        $wageCalc    = new ShiftWageCalculator();

        $coveredDates = [];
        foreach ($shifts as $s) {
            $uid  = (int) ($s['user_id'] ?? 0);
            $date = trim($s['date'] ?? '');
            if ($uid > 0 && $date !== '') {
                $coveredDates[$uid][$date] = true;
            }
        }

        $count   = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($shifts as $s) {
            if (($s['skip'] ?? '') === '1') { $skipped++; continue; }
            $userId = (int) ($s['user_id'] ?? 0);
            if ($userId <= 0) continue;

            $date      = trim($s['date'] ?? '');
            $startTime = trim($s['start_time'] ?? '00:00');
            $endTime   = trim($s['end_time']   ?? '00:00');
            if ($date === '') continue;

            $start    = strtotime($startTime);
            $end      = strtotime($endTime);
            $duration = (int) (($end - $start) / 60);
            $cross    = 0;
            if ($duration < 0) { $duration += 24 * 60; $cross = 1; }
            $endsDate = $cross ? date('Y-m-d', strtotime($date . ' +1 day')) : $date;

            $pause      = ($pauseAfter > 0 && $duration >= $pauseAfter) ? $pauseDur : 0;
            $netMinutes = $duration - $pause;

            $wage            = $wageCalc->calculate($startTime, $endTime, $shiftTypes, $netMinutes);
            $shiftTypeId     = $wage['dominant_type_id'];
            $estimatedSalary = $wage['estimated_salary'];

            $hasExact    = false;
            $toDeleteIds = [];
            foreach ($this->shifts->findByUserAndDate($userId, $storeId, $date) as $ex) {
                $exCross = (int) ($ex['cross_midnight'] ?? 0);
                if (($ex['start_time'] ?? '') === $startTime && ($ex['end_time'] ?? '') === $endTime) {
                    $hasExact = true;
                } elseif ($this->timeSlotsOverlap($date, $startTime, $endTime, $cross, $ex['shift_date'], $ex['start_time'] ?? '', $ex['end_time'] ?? '', $exCross)) {
                    $toDeleteIds[] = (int) $ex['id'];
                }
            }
            if ($hasExact) { $skipped++; continue; }
            foreach ($toDeleteIds as $delId) { $this->shifts->delete($delId); }

            if (!empty($toDeleteIds)) { $updated++; } else { $count++; }

            $record = [
                'store_id'         => $storeId,
                'user_id'          => $userId,
                'shift_type_id'    => $shiftTypeId,
                'shift_date'       => $date,
                'start_time'       => $startTime,
                'end_time'         => $endTime,
                'duration_minutes' => $duration,
                'pause_minutes'    => $pause,
                'cross_midnight'   => $cross,
                'starts_at'        => $date . ' ' . $startTime . ':00',
                'ends_at'          => $endsDate . ' ' . $endTime . ':00',
                'estimated_salary' => $estimatedSalary > 0 ? $estimatedSalary : null,
                'notes'            => null,
            ];

            $this->shifts->save($record);
        }

        $obsoleteDeleted = 0;
        if (!empty($coveredDates)) {
            $allDates      = array_merge(...array_map('array_keys', $coveredDates));
            $importMinDate = min($allDates);
            $importMaxDate = max($allDates);

            foreach ($coveredDates as $uid => $datesMap) {
                foreach ($this->shifts->findByUser($uid) as $ex) {
                    if ((int) ($ex['store_id'] ?? 0) !== $storeId) continue;
                    $exDate = $ex['shift_date'] ?? '';
                    if ($exDate < $importMinDate || $exDate > $importMaxDate) continue;
                    if (!isset($datesMap[$exDate])) {
                        $this->shifts->delete((int) $ex['id']);
                        $obsoleteDeleted++;
                    }
                }
            }
        }

        $this->saveImportAliases($storeId, $shifts);

        $this->auditLogger->log($request, 'shifts.imported', 'shift', null, [
            'store_id'         => $storeId,
            'count'            => $count,
            'updated'          => $updated,
            'skipped'          => $skipped,
            'deleted_obsolete' => $obsoleteDeleted,
        ], $storeId);
        return Response::redirect($this->base() . '/admin/shifts?success=imported&count=' . ($count + $updated));
    }

    /**
     * @return int[] IDs des utilisateurs membres du magasin donné.
     */
    private function storeMemberIds(int $storeId): array
    {
        return array_map(fn($su) => (int) $su['user_id'], $this->storeUsers->findByStore($storeId));
    }

    /**
     * Résout automatiquement le user_id de chaque entrée importée par correspondance
     * de nom, en ne matchant que sur les employés du magasin courant ($storeUsers).
     * Deux employés de magasins différents peuvent partager le même nom de famille :
     * matcher sur l'ensemble des utilisateurs de l'application ferait alors assigner
     * le shift au mauvais utilisateur (et donc apparaître un staff d'un autre magasin
     * dans les shifts / rapports journaliers de ce magasin).
     *
     * @param array<array{staff_name:string}> $entries
     * @param array $storeUsers Utilisateurs membres du magasin courant uniquement
     * @param array<string,int> $aliases Alias nom -> user_id déjà connus pour ce magasin
     * @return array Entrées enrichies du champ 'user_id'
     */
    private function resolveEntryUserIds(array $entries, array $storeUsers, array $aliases): array
    {
        $nameIndex = [];
        foreach ($storeUsers as $u) {
            $uid     = (int) $u['id'];
            $full    = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
            $display = trim($u['display_name'] ?? '');
            if ($full !== '')    $nameIndex[mb_strtolower($full)]    = $uid;
            if ($display !== '') $nameIndex[mb_strtolower($display)] = $uid;
        }

        foreach ($entries as &$e) {
            $key = mb_strtolower($e['staff_name']);
            $e['user_id'] = $nameIndex[$key] ?? $aliases[$key] ?? 0;
        }
        unset($e);

        return $entries;
    }

    private function mergeAdjacentEntries(array $entries): array
    {
        $groups = [];
        foreach ($entries as $e) {
            $key = trim($e['date']) . '|' . mb_strtolower(trim($e['staff_name']));
            $groups[$key][] = $e;
        }

        $result = [];
        foreach ($groups as $group) {
            usort($group, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
            $merged = $group[0];
            for ($i = 1; $i < count($group); $i++) {
                $cur = $group[$i];
                $prevEnd   = $merged['end_time'];
                $currStart = $cur['start_time'];
                $currEnd   = $cur['end_time'];
                if ($currStart <= $prevEnd) {
                    if ($currEnd > $prevEnd) $merged['end_time'] = $currEnd;
                    $mergedHours = (float) $merged['hours'];
                    $curHours    = (float) $cur['hours'];
                    $merged['hours'] = ($currStart < $prevEnd) ? (string) max($mergedHours, $curHours) : (string) round($mergedHours + $curHours, 1);
                } else {
                    $result[] = $merged;
                    $merged   = $cur;
                }
            }
            $result[] = $merged;
        }
        return $result;
    }

    private function timeSlotsOverlap(string $dateA, string $startA, string $endA, int $crossA, string $dateB, string $startB, string $endB, int $crossB): bool
    {
        $tsStartA = strtotime($dateA . ' ' . $startA);
        $tsEndA   = $crossA ? strtotime(date('Y-m-d', strtotime($dateA . ' +1 day')) . ' ' . $endA) : strtotime($dateA . ' ' . $endA);
        $tsStartB = strtotime($dateB . ' ' . $startB);
        $tsEndB   = $crossB ? strtotime(date('Y-m-d', strtotime($dateB . ' +1 day')) . ' ' . $endB) : strtotime($dateB . ' ' . $endB);
        if ($tsStartA === false || $tsEndA === false || $tsStartB === false || $tsEndB === false) return false;
        return $tsStartA < $tsEndB && $tsStartB < $tsEndA;
    }

    private function pruneStoreUploads(string $dir, int $keep = 5): void
    {
        $files = glob($dir . 'Upload_*');
        if ($files === false || count($files) <= $keep) return;
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - $keep) as $old) @unlink($old);
    }

    private function saveImportAliases(int $storeId, array $shifts): void
    {
        foreach ($shifts as $s) {
            if (($s['skip'] ?? '0') === '1') continue;
            $name   = mb_strtolower(trim($s['staff_name'] ?? ''));
            $userId = (int) ($s['user_id'] ?? 0);
            if ($name === '') continue;
            if ($userId > 0) $this->importAliases->upsert($storeId, $name, $userId);
            else $this->importAliases->deleteAlias($storeId, $name);
        }
    }
}
