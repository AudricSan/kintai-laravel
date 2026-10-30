<?php

declare(strict_types=1);

namespace App\Services;

use App\Extension\Absences;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreUserRepositoryInterface;
use App\Repositories\UserRepositoryInterface;

/**
 * Semaine type : lit les shifts d'une semaine sous forme d'« entrées » (jour 0 à 6, employé, horaires) et les recrée
 * sur une autre semaine. Sert à « Copier la semaine précédente » et aux modèles de semaine enregistrés.
 *
 * Une entrée n'est pas recréée (et le résultat dit pourquoi) quand l'employé n'est plus membre actif du magasin, a un
 * congé approuvé ce jour-là, s'est déclaré indisponible ce jour de la semaine, ou a déjà un shift qui chevauche.
 */
final class WeekPlanner
{
    /** Raisons d'une entrée écartée, dans l'ordre où elles sont vérifiées. */
    public const SKIP_NOT_MEMBER  = 'not_member';
    public const SKIP_TIMEOFF     = 'timeoff';
    public const SKIP_UNAVAILABLE = 'unavailable';
    public const SKIP_OVERLAP     = 'overlap';

    public function __construct(
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly UserRepositoryInterface $users,
        private readonly AvailabilityGuard $availability,
    ) {}

    /**
     * Entrées de la semaine qui commence le $weekStart (7 jours), triées par jour puis heure de début.
     *
     * @return list<array{day: int, user_id: ?int, start_time: string, end_time: string, pause_minutes: int, notes: ?string, is_open: int, open_shift_note: ?string}>
     */
    public function entriesOfWeek(int $storeId, string $weekStart): array
    {
        $start = new \DateTimeImmutable($weekStart);
        $end   = $start->modify('+6 days')->format('Y-m-d');
        $entries = [];
        foreach (Shift::findByStoreBetween($storeId, $start->format('Y-m-d'), $end) as $s) {
            if (!empty($s['deleted_at'])) {
                continue;
            }
            $day = (int) $start->diff(new \DateTimeImmutable((string) $s['shift_date']))->days;
            $entries[] = self::entry([
                'day'             => $day,
                'user_id'         => $s['user_id'] ?? null,
                'start_time'      => $s['start_time'] ?? '',
                'end_time'        => $s['end_time'] ?? '',
                'pause_minutes'   => $s['pause_minutes'] ?? 0,
                'notes'           => $s['notes'] ?? null,
                'is_open'         => $s['is_open'] ?? 0,
                'open_shift_note' => $s['open_shift_note'] ?? null,
            ]);
        }
        usort($entries, fn(array $a, array $b): int => [$a['day'], $a['start_time']] <=> [$b['day'], $b['start_time']]);
        return $entries;
    }

    /**
     * Recrée les entrées sur la semaine qui commence le $targetStart.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{created: list<array<string, mixed>>, skipped: list<array{entry: array<string, mixed>, date: string, reason: string}>}
     */
    public function apply(int $storeId, string $targetStart, array $entries, ?int $createdBy = null): array
    {
        $start   = new \DateTimeImmutable($targetStart);
        $members = $this->activeMemberIds($storeId);
        $types   = ShiftType::findActive($storeId);
        $created = [];
        $skipped = [];

        foreach ($entries as $raw) {
            $entry = self::entry($raw);
            if ($entry['start_time'] === '' || $entry['end_time'] === '' || $entry['day'] < 0 || $entry['day'] > 6) {
                continue;
            }
            $date   = $start->modify('+' . $entry['day'] . ' days')->format('Y-m-d');
            $userId = $entry['is_open'] === 1 ? null : $entry['user_id'];

            $reason = $userId !== null ? $this->skipReason($storeId, $userId, $date, $entry, $members) : null;
            if ($reason !== null) {
                $skipped[] = ['entry' => $entry, 'date' => $date, 'reason' => $reason];
                continue;
            }

            $startMin  = self::minutes($entry['start_time']);
            $endMin    = self::minutes($entry['end_time']);
            $duration  = $endMin - $startMin;
            $cross     = $duration < 0 ? 1 : 0;
            $duration += $cross === 1 ? 24 * 60 : 0;
            $endsDate  = $cross === 1 ? $start->modify('+' . ($entry['day'] + 1) . ' days')->format('Y-m-d') : $date;
            $typeId    = (new ShiftWageCalculator())->calculate($entry['start_time'], $entry['end_time'], $types, max(0, $duration - $entry['pause_minutes']))['dominant_type_id'];

            $created[] = Shift::put([
                'store_id'         => $storeId,
                'user_id'          => $userId,
                'shift_type_id'    => $typeId,
                'shift_date'       => $date,
                'start_time'       => $entry['start_time'],
                'end_time'         => $entry['end_time'],
                'duration_minutes' => $duration,
                'pause_minutes'    => $entry['pause_minutes'],
                'cross_midnight'   => $cross,
                'starts_at'        => $date . ' ' . $entry['start_time'] . ':00',
                'ends_at'          => $endsDate . ' ' . $entry['end_time'] . ':00',
                'notes'            => $entry['notes'],
                'is_open'          => $entry['is_open'],
                'open_shift_note'  => $entry['is_open'] === 1 ? $entry['open_shift_note'] : null,
                'created_by'       => $createdBy,
            ]);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Entrée normalisée (horaires en HH:MM) : seule forme stockée dans un modèle de semaine.
     *
     * @param array<string, mixed> $raw
     * @return array{day: int, user_id: ?int, start_time: string, end_time: string, pause_minutes: int, notes: ?string, is_open: int, open_shift_note: ?string}
     */
    public static function entry(array $raw): array
    {
        $time = static fn(mixed $v): string => preg_match('/^(\d{1,2}):(\d{2})/', (string) $v, $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60
            ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2])
            : '';
        $uid = (int) ($raw['user_id'] ?? 0);
        return [
            'day'             => (int) ($raw['day'] ?? -1),
            'user_id'         => $uid > 0 ? $uid : null,
            'start_time'      => $time($raw['start_time'] ?? ''),
            'end_time'        => $time($raw['end_time'] ?? ''),
            'pause_minutes'   => max(0, (int) ($raw['pause_minutes'] ?? 0)),
            'notes'           => ($n = trim((string) ($raw['notes'] ?? ''))) !== '' ? $n : null,
            'is_open'         => !empty($raw['is_open']) ? 1 : 0,
            'open_shift_note' => ($o = trim((string) ($raw['open_shift_note'] ?? ''))) !== '' ? $o : null,
        ];
    }

    /** @param array<int, true> $members @param array<string, mixed> $entry */
    private function skipReason(int $storeId, int $userId, string $date, array $entry, array $members): ?string
    {
        if (!isset($members[$userId])) {
            return self::SKIP_NOT_MEMBER;
        }
        foreach (Absences::current()->forUser($userId) as $absence) {
            if (($absence['status'] ?? '') === 'approved'
                && ($absence['start_date'] ?? '') <= $date && $date <= ($absence['end_date'] ?? '')) {
                return self::SKIP_TIMEOFF;
            }
        }
        if ($this->availability->unavailableWeekday($userId, $storeId, $date) !== null) {
            return self::SKIP_UNAVAILABLE;
        }
        [$from, $to] = self::interval($entry['start_time'], $entry['end_time']);
        foreach (Shift::findByUserAndDate($userId, $storeId, $date) as $existing) {
            if (!empty($existing['deleted_at'])) {
                continue;
            }
            [$exFrom, $exTo] = self::interval((string) ($existing['start_time'] ?? ''), (string) ($existing['end_time'] ?? ''));
            if ($from < $exTo && $exFrom < $to) {
                return self::SKIP_OVERLAP;
            }
        }
        return null;
    }

    /** @return array<int, true> employés actifs (compte actif, non supprimé) membres du magasin */
    private function activeMemberIds(int $storeId): array
    {
        $active = [];
        foreach ($this->users->findAll() as $u) {
            if (empty($u['deleted_at']) && (int) ($u['is_active'] ?? 1) === 1) {
                $active[(int) $u['id']] = true;
            }
        }
        $ids = [];
        foreach ($this->storeUsers->findByStore($storeId) as $m) {
            if (isset($active[(int) $m['user_id']])) {
                $ids[(int) $m['user_id']] = true;
            }
        }
        return $ids;
    }

    /** @return array{0: int, 1: int} minutes depuis minuit, fin après le début (passage de minuit compris) */
    private static function interval(string $start, string $end): array
    {
        $from = self::minutes($start);
        $to   = self::minutes($end);
        return [$from, $to <= $from ? $to + 24 * 60 : $to];
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)) + [0, 0]);
        return $h * 60 + $m;
    }
}
