<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\ShiftWageCalculator;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Génère des données d'exemple réalistes pour tester l'ensemble des bundles (congés, échanges de shift, candidatures
 * shift ouvert, pointages, messagerie, photos de magasin, démissions, rapports de salaire) sur l'instance locale.
 *
 * Les lectures passent par les modèles ; les écritures, par des requêtes PDO préparées sur la connexion de Laravel (les
 * tables des bundles n'ont pas de modèle dans le Core). Idempotent : un marqueur « demo_data_seeded_at » est écrit dans
 * app_settings, une seconde exécution sans --force ne fait rien.
 */
final class SeedDemoDataCommand extends Command
{
    private const MARKER_KEY = 'demo_data_seeded_at';

    protected $signature = 'kintai:seed-demo-data {--force : relance la génération (les données présentes sont conservées)}';

    protected $description = 'Génère des données de démonstration pour les bundles (idempotent)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        if (!$force && AppSetting::value(self::MARKER_KEY) !== null) {
            $this->line('[Kintai] Données de démo déjà générées le ' . AppSetting::value(self::MARKER_KEY) . '. Utilise --force pour relancer.');

            return self::SUCCESS;
        }

        $wageCalc        = new ShiftWageCalculator();
        $compressor     = 'kintai\Bundles\StorePhoto\Services\ImageCompressionService'; // bundle store-photos
        $imageCompressor = app()->make($compressor);
        /** @var PDO $pdo */
        $pdo = DB::connection()->getPdo();
        // Erreurs en exceptions : un prepare() invalide (mauvaise colonne) doit s'arrêter proprement.
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->line('[Kintai] Génération des données de démo...');

        $today = new DateTimeImmutable('today');
        $now   = $today->format('Y-m-d H:i:s');

        $allStores = Store::findAll();
        $allUsers  = array_values(array_filter(User::findAll(), static fn($u) => (int) ($u['is_active'] ?? 0) === 1));
        
        $membersByStore = [];
        foreach ($allStores as $store) {
            $sid = (int) $store['id'];
            $memberIds = array_map(static fn($m) => (int) $m['user_id'], StoreUser::findByStore($sid));
            $membersByStore[$sid] = array_values(array_filter(
                $allUsers,
                static fn($u) => in_array((int) $u['id'], $memberIds, true)
            ));
        }
        
        // ─── 1. Congés (timeoff_requests) ────────────────────────────────────────
        
        $timeoffTypes    = ['vacation', 'sick', 'personal', 'unpaid', 'other'];
        $timeoffStatuses = ['pending', 'approved', 'approved', 'refused'];
        $countTimeoff    = 0;
        
        foreach ($allStores as $store) {
            $sid     = (int) $store['id'];
            $members = $membersByStore[$sid];
            if ($members === []) {
                continue;
            }
            foreach (self::pickN($members, min(8, count($members))) as $member) {
                $offset = random_int(-25, 45);
                $start  = $today->modify("$offset days");
                $end    = $start->modify(random_int(0, 4) . ' days');
                $status = self::pick($timeoffStatuses);
                self::insertRow($pdo, 'timeoff_requests', [
                    'user_id'      => (int) $member['id'],
                    'store_id'     => $sid,
                    'start_date'   => $start->format('Y-m-d'),
                    'end_date'     => $end->format('Y-m-d'),
                    'type'         => self::pick($timeoffTypes),
                    'status'       => $status,
                    'reason'       => 'Congé de démonstration',
                    'review_notes' => $status !== 'pending' ? ($status === 'approved' ? 'Approuvé (donnée de démo).' : 'Refusé pour sous-effectif (donnée de démo).') : null,
                    'reviewed_by'  => $status !== 'pending' ? (int) self::pick($allUsers)['id'] : null,
                    'reviewed_at'  => $status !== 'pending' ? $now : null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
                $countTimeoff++;
            }
        }
        $this->line("  - timeoff_requests : $countTimeoff");
        
        // ─── 2. Échanges de shift (shift_swap_requests) ──────────────────────────
        
        $swapStatuses = ['pending', 'pending', 'accepted', 'refused'];
        $countSwaps   = 0;
        
        foreach ($allStores as $store) {
            $sid     = (int) $store['id'];
            $members = $membersByStore[$sid];
            if (count($members) < 2) {
                continue;
            }
            $futureShifts = array_values(array_filter(
                Shift::findByStore($sid),
                static fn($s) => $s['shift_date'] >= $today->format('Y-m-d') && $s['user_id'] !== null
            ));
            $byUser = [];
            foreach ($futureShifts as $s) {
                $byUser[(int) $s['user_id']][] = $s;
            }
            $eligibleUserIds = array_keys($byUser);
            if (count($eligibleUserIds) < 2) {
                continue;
            }
            foreach (self::pickN($eligibleUserIds, min(6, count($eligibleUserIds))) as $requesterId) {
                $targetCandidates = array_values(array_diff($eligibleUserIds, [$requesterId]));
                if ($targetCandidates === []) {
                    continue;
                }
                $targetId = self::pick($targetCandidates);
                $status   = self::pick($swapStatuses);
                $reviewed = $status !== 'pending';
                self::insertRow($pdo, 'shift_swap_requests', [
                    'store_id'           => $sid,
                    'requester_id'       => $requesterId,
                    'target_id'          => $targetId,
                    'requester_shift_id' => (int) self::pick($byUser[$requesterId])['id'],
                    'target_shift_id'    => (int) self::pick($byUser[$targetId])['id'],
                    'reason'             => 'Échange de démonstration',
                    'status'             => $status,
                    'peer_accepted_at'   => $status === 'accepted' ? $now : null,
                    'reviewed_by'        => $reviewed ? (int) self::pick($allUsers)['id'] : null,
                    'reviewed_at'        => $reviewed ? $now : null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
                $countSwaps++;
            }
        }
        $this->line("  - shift_swap_requests : $countSwaps");
        
        // ─── 3. Bourse aux shifts (shift_claims) ─────────────────────────────────
        
        $countPublished = 0;
        $countClaims    = 0;
        $claimStatuses  = ['pending', 'pending', 'approved', 'refused'];
        
        foreach ($allStores as $store) {
            $sid     = (int) $store['id'];
            $members = $membersByStore[$sid];
            if (count($members) < 2) {
                continue;
            }
            $upcomingAssigned = array_values(array_filter(
                Shift::findByStore($sid),
                static fn($s) => $s['shift_date'] > $today->format('Y-m-d') && $s['user_id'] !== null && (int) ($s['is_open'] ?? 0) === 0
            ));
            $toPublish = self::pickN($upcomingAssigned, 4);
            $i = 0;
            foreach ($toPublish as $shift) {
                $shiftId         = (int) $shift['id'];
                $originalOwnerId = (int) $shift['user_id'];
                self::updateRow($pdo, 'shifts', $shiftId, ['user_id' => null, 'is_open' => 1]);
                $countPublished++;
        
                // La dernière shift publiée de chaque store reste non pourvue (aucune candidature).
                $isLast = (++$i === count($toPublish));
                if ($isLast) {
                    continue;
                }
        
                $candidateIds = array_values(array_diff(
                    array_map(static fn($m) => (int) $m['id'], $members),
                    [$originalOwnerId]
                ));
                if ($candidateIds === []) {
                    continue;
                }
                $claimantId = self::pick($candidateIds);
                $status     = self::pick($claimStatuses);
                $resolved   = $status !== 'pending';
                $claimId = self::insertRow($pdo, 'shift_claims', [
                    'shift_id'    => $shiftId,
                    'user_id'     => $claimantId,
                    'store_id'    => $sid,
                    'status'      => $status,
                    'note'        => 'Candidature de démonstration',
                    'claimed_at'  => $now,
                    'resolved_at' => $resolved ? $now : null,
                    'resolved_by' => $resolved ? (int) self::pick($allUsers)['id'] : null,
                ]);
                $countClaims++;
        
                if ($status === 'approved') {
                    self::updateRow($pdo, 'shifts', $shiftId, ['user_id' => $claimantId, 'is_open' => 0]);
                }
            }
        }
        $this->line("  - shifts publiés à la bourse : $countPublished (dont non pourvus intentionnellement)");
        $this->line("  - shift_claims : $countClaims");
        
        // ─── 4. Pointages (timeclocks) ───────────────────────────────────────────
        
        $countTimeclocks = 0;
        $countForgotten  = 0;
        $windowStart     = $today->modify('-21 days')->format('Y-m-d');
        
        foreach ($allStores as $store) {
            $sid = (int) $store['id'];
            $pastShifts = array_values(array_filter(
                Shift::findByStore($sid),
                static fn($s) => $s['shift_date'] < $today->format('Y-m-d')
                    && $s['shift_date'] >= $windowStart
                    && $s['user_id'] !== null
            ));
            foreach (self::pickN($pastShifts, min(80, count($pastShifts))) as $shift) {
                $shiftDate = new DateTimeImmutable((string) $shift['shift_date']);
                [$startH, $startM] = array_map('intval', explode(':', (string) $shift['start_time']));
                [$endH, $endM]     = array_map('intval', explode(':', (string) $shift['end_time']));
                $clockIn  = $shiftDate->setTime($startH, $startM);
                $clockOut = $shiftDate->setTime($endH, $endM);
                if ($clockOut <= $clockIn) {
                    $clockOut = $clockOut->modify('+1 day');
                }
        
                // ~15% de retard (5 à 18 min), le reste ponctuel à quelques minutes près.
                $lateChance = random_int(1, 100) <= 15;
                $clockIn    = $clockIn->modify(($lateChance ? random_int(5, 18) : random_int(-3, 4)) . ' minutes');
                $clockOut   = $clockOut->modify(random_int(-4, 6) . ' minutes');
        
                // ~5% de pointage oublié (pas de clock-out).
                $forgotten = random_int(1, 100) <= 5;
        
                self::insertRow($pdo, 'timeclocks', [
                    'store_id'         => $sid,
                    'user_id'          => (int) $shift['user_id'],
                    'shift_date'       => (string) $shift['shift_date'],
                    'clock_in_time'    => $clockIn->format('Y-m-d H:i:s'),
                    'clock_out_time'   => $forgotten ? null : $clockOut->format('Y-m-d H:i:s'),
                    'duration_minutes' => $forgotten ? null : (int) round(($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60),
                    'notes'            => null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
                $countTimeclocks++;
                if ($forgotten) {
                    $countForgotten++;
                }
            }
        }
        $this->line("  - timeclocks : $countTimeclocks (dont $countForgotten pointages oubliés)");
        
        // ─── 5. Messagerie (message_threads / participants / messages) ──────────
        
        $countThreads = 0;
        $threadSubjects = ['Planning de la semaine', 'Question sur le shift de demain', 'Remplacement urgent', 'Note de service', 'Pause déjeuner'];
        $threadBodies   = [
            "Bonjour, est-ce que quelqu'un peut prendre mon créneau de demain matin ?",
            'Merci de confirmer votre présence pour le shift de ce week-end.',
            "Petit rappel : badge obligatoire à l'entrée du magasin.",
            'Le planning du mois prochain est disponible, merci de le consulter.',
            "Ok, c'est noté, merci !",
        ];
        
        foreach ($allStores as $store) {
            $sid     = (int) $store['id'];
            $members = $membersByStore[$sid];
            if (count($members) < 2) {
                continue;
            }
            foreach (range(1, 2) as $i) {
                $participants = self::pickN($members, min(random_int(2, 4), count($members)));
                $creator      = $participants[0];
                $threadId = self::insertRow($pdo, 'message_threads', [
                    'store_id'   => $sid,
                    'creator_id' => (int) $creator['id'],
                    'subject'    => self::pick($threadSubjects),
                    'created_at' => $now,
                ]);
                foreach ($participants as $idx => $p) {
                    self::insertRow($pdo, 'thread_participants', [
                        'thread_id' => $threadId,
                        'user_id'   => (int) $p['id'],
                        'is_read'   => $idx === 0 ? 1 : 0,
                    ]);
                }
                foreach (self::pickN($participants, min(3, count($participants))) as $sender) {
                    self::insertRow($pdo, 'thread_messages', [
                        'thread_id' => $threadId,
                        'sender_id' => (int) $sender['id'],
                        'body'      => self::pick($threadBodies),
                        'sent_at'   => $now,
                    ]);
                }
                $countThreads++;
            }
        }
        $this->line("  - message_threads : $countThreads");
        
        // ─── 6. Photos de magasin (store_photo_submissions / images) ────────────
        
        $countSubmissions = 0;
        $countImages      = 0;
        $weekLabel = $today->format('Y') . '-W' . $today->format('W');
        
        foreach ($allStores as $store) {
            $sid = (int) $store['id'];
            $submissionId = self::insertRow($pdo, 'store_photo_submissions', [
                'store_id'       => $sid,
                'week_label'     => $weekLabel,
                'image_count'    => 0,
                'retention_days' => 14,
                'created_by'     => (int) (self::pick($membersByStore[$sid] ?: $allUsers))['id'],
            ]);
        
            $subDir = BASE_PATH . '/storage/uploads/img/' . $sid . '/' . $submissionId . '/';
            if (!is_dir($subDir)) {
                mkdir($subDir, 0775, true);
            }
        
            $imagesSaved = 0;
            foreach (range(1, 2) as $idx) {
                $tmpFile = $subDir . 'source_' . $idx . '.png';
                $img = imagecreatetruecolor(800, 600);
                $color = imagecolorallocate($img, random_int(80, 200), random_int(80, 200), random_int(80, 200));
                imagefilledrectangle($img, 0, 0, 799, 599, $color);
                imagepng($img, $tmpFile);
                imagedestroy($img);
        
                $compressed = $imageCompressor->compress($tmpFile, $subDir . 'photo_' . $idx);
                @unlink($tmpFile);
                if ($compressed === null) {
                    continue;
                }
                $filename = 'photo_' . $idx . '.' . $compressed['extension'];
                self::insertRow($pdo, 'store_photo_images', [
                    'submission_id' => $submissionId,
                    'filename'      => $filename,
                    'filepath'      => 'storage/img/' . $sid . '/' . $submissionId . '/' . $filename,
                    'filesize'      => $compressed['size'],
                    'mime_type'     => $compressed['mime'],
                    'sort_order'    => $idx - 1,
                ]);
                $imagesSaved++;
                $countImages++;
            }
            self::updateRow($pdo, 'store_photo_submissions', $submissionId, ['image_count' => $imagesSaved]);
            $countSubmissions++;
        }
        $this->line("  - store_photo_submissions : $countSubmissions ($countImages images)");
        
        
        // ─── 8. Démissions (utilisateurs synthétiques + resignation_reports) ────
        
        $syntheticResignees = [
            ['first_name' => 'Démo', 'last_name' => 'Démission1', 'email' => 'demo.resignation1@kintai.test'],
            ['first_name' => 'Démo', 'last_name' => 'Démission2', 'email' => 'demo.resignation2@kintai.test'],
            ['first_name' => 'Démo', 'last_name' => 'Démission3', 'email' => 'demo.resignation3@kintai.test'],
        ];
        $countResignations = 0;
        
        foreach ($syntheticResignees as $idx => $person) {
            $existing = User::findByEmail($person['email']);
            if ($existing !== null) {
                $resignUserId   = (int) $existing['id'];
                $resignUserName = (string) $existing['display_name'];
            } else {
                $displayName = $person['first_name'] . ' ' . $person['last_name'];
                $resignUserId = self::insertRow($pdo, 'users', [
                    'first_name'    => $person['first_name'],
                    'last_name'     => $person['last_name'],
                    'display_name'  => $displayName,
                    'email'         => $person['email'],
                    'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 12]),
                    'is_active'     => 0,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $resignUserName = $displayName;
            }
            $store = $allStores[$idx % count($allStores)];
            self::insertRow($pdo, 'resignation_reports', [
                'store_id'           => (int) $store['id'],
                'user_id'            => $resignUserId,
                'employee_number'    => 'DEMO-' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT),
                'employee_name'      => $resignUserName,
                'resignation_date'   => $today->modify('-' . random_int(5, 60) . ' days')->format('Y-m-d'),
                'reason'             => 'Convenance personnelle (donnée de démonstration).',
                'resignation_notice' => "Préavis d'un mois respecté.",
                'person_in_charge'   => (string) (self::pick($allUsers)['display_name'] ?? ''),
                'created_at'         => $now,
            ]);
            $countResignations++;
        }
        $this->line("  - resignation_reports : $countResignations");
        
        // ─── 9. Rapports de salaire (salary_reports) ─────────────────────────────
        
        $countSalaryReports = 0;
        
        foreach ($allStores as $store) {
            $sid = (int) $store['id'];
            $shiftTypesMap = [];
            foreach (ShiftType::findByStore($sid) as $type) {
                $shiftTypesMap[(int) $type['id']] = $type;
            }
        
            foreach (range(1, 3) as $monthsAgo) {
                $targetMonth = $today->modify("-$monthsAgo months")->format('Y-m');
                $already = (int) $pdo->query(
                    "SELECT COUNT(*) FROM salary_reports WHERE store_id = $sid AND target_month = '$targetMonth' AND user_id IS NULL"
                )->fetchColumn();
                if ($already > 0) {
                    continue;
                }
        
                $monthShifts = array_values(array_filter(
                    Shift::findByStore($sid),
                    static fn($s) => str_starts_with((string) $s['shift_date'], $targetMonth) && $s['user_id'] !== null
                ));
                if ($monthShifts === []) {
                    continue;
                }
        
                $totalPayment = 0.0;
                $totalMinutes = 0;
                $employeeIds  = [];
                foreach ($monthShifts as $shift) {
                    $cost = $wageCalc->costOf($shift, $shiftTypesMap);
                    $totalPayment += $cost['amount'];
                    $totalMinutes += $cost['net_minutes'];
                    $employeeIds[(int) $shift['user_id']] = true;
                }
        
                self::insertRow($pdo, 'salary_reports', [
                    'store_id'              => $sid,
                    'target_month'          => $targetMonth,
                    'store_name'            => (string) ($store['name'] ?? ''),
                    'total_payment'         => round($totalPayment, 2),
                    'net_payment'           => round($totalPayment, 2),
                    'staff_man_hours'       => round($totalMinutes / 60, 2),
                    'staff_total_payment'   => round($totalPayment, 2),
                    'staff_avg_hourly_wage' => $totalMinutes > 0 ? round($totalPayment / ($totalMinutes / 60), 2) : 0.0,
                    'active_employees'      => count($employeeIds),
                    'remarks'               => 'Rapport de démonstration généré automatiquement.',
                    'created_at'            => $now,
                ]);
                $countSalaryReports++;
            }
        }
        $this->line("  - salary_reports : $countSalaryReports");
        
        // ─── Marqueur d'exécution ─────────────────────────────────────────────────
        
        AppSetting::put(self::MARKER_KEY, $now);

        return self::SUCCESS;
    }

    private static function insertRow(PDO $pdo, string $table, array $data): int
    {
        $cols   = array_keys($data);
        $colSql = implode(',', array_map(static fn($col) => "\"$col\"", $cols));
        $phSql  = implode(',', array_map(static fn($col) => ":$col", $cols));
        $stmt   = $pdo->prepare("INSERT INTO \"$table\" ($colSql) VALUES ($phSql)");
        $params = [];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }
    
    private static function updateRow(PDO $pdo, string $table, int $id, array $data): void
    {
        $sets = implode(',', array_map(static fn($col) => "\"$col\" = :$col", array_keys($data)));
        $stmt = $pdo->prepare("UPDATE \"$table\" SET $sets WHERE id = :__id");
        $params = [':__id' => $id];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        $stmt->execute($params);
    }
    
    private static function pick(array $arr)
    {
        return $arr[array_rand($arr)];
    }
    
    private static function pickN(array $arr, int $n): array
    {
        $arr = array_values($arr);
        if ($n <= 0 || $arr === []) {
            return [];
        }
        if (count($arr) <= $n) {
            return $arr;
        }
        $keys = array_rand($arr, $n);
        $keys = is_array($keys) ? $keys : [$keys];
        return array_map(static fn($k) => $arr[$k], $keys);
    }

}
