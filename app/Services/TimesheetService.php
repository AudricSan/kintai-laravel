<?php

declare(strict_types=1);

namespace App\Services;

use App\Extension\Attendances;
use App\Models\Shift;

/**
 * Feuille de temps d'une semaine : chaque shift prévu d'un employé est rapproché de son pointage (fournisseurs de
 * pointages des bundles, voir Attendances), et l'écart est qualifié (retard, départ anticipé, absence, pointage hors
 * planning, sortie oubliée). Les totaux par employé comparent le temps prévu et le temps pointé, pauses déduites.
 *
 * Un pointage est rapproché, pour le même employé et le même jour, du shift dont le début est le plus proche de
 * l'arrivée. Une tolérance (GRACE_MINUTES) évite de signaler quelques minutes d'écart.
 */
final class TimesheetService
{
    public const GRACE_MINUTES = 5;

    /** Statuts d'une ligne, du plus grave au moins grave (ordre d'affichage des compteurs). */
    public const NO_SHOW           = 'no_show';
    public const MISSING_CLOCK_OUT = 'missing_clock_out';
    public const UNPLANNED         = 'unplanned';
    public const LATE              = 'late';
    public const EARLY_LEAVE       = 'early_leave';
    public const OVERTIME          = 'overtime';
    public const IN_PROGRESS       = 'in_progress';
    public const UPCOMING          = 'upcoming';
    public const OK                = 'ok';

    /** Statuts qui demandent l'attention du gérant avant de valider la semaine. */
    public const ANOMALIES = [self::NO_SHOW, self::MISSING_CLOCK_OUT, self::UNPLANNED, self::LATE, self::EARLY_LEAVE];

    public function __construct(
        private readonly ?Attendances $attendances = null,
    ) {}

    /**
     * @return array{
     *     from: string, to: string,
     *     rows: list<array{user_id: int, date: string, shift: ?array<string, mixed>, attendance: ?array<string, mixed>,
     *                      planned_minutes: int, worked_minutes: int, late_minutes: int, early_minutes: int,
     *                      overtime_minutes: int, flags: list<string>}>,
     *     totals: array<int, array{planned_minutes: int, worked_minutes: int, anomalies: int}>,
     *     counts: array<string, int>,
     *     fingerprint: string
     * }
     */
    public function week(int $storeId, string $weekStart, ?\DateTimeImmutable $now = null): array
    {
        $now  ??= new \DateTimeImmutable();
        $from   = (new \DateTimeImmutable($weekStart))->format('Y-m-d');
        $to     = (new \DateTimeImmutable($from))->modify('+6 days')->format('Y-m-d');

        // Shifts attribués (un shift ouvert n'a pas d'employé à pointer), groupés par employé et jour.
        $planned = [];
        foreach (Shift::findByStoreBetween($storeId, $from, $to) as $s) {
            $uid = (int) ($s['user_id'] ?? 0);
            if ($uid <= 0 || !empty($s['deleted_at'])) {
                continue;
            }
            $planned[$uid . '|' . $s['shift_date']][] = $s;
        }
        $clocked = [];
        foreach (($this->attendances ?? Attendances::current())->forStoreBetween($storeId, $from, $to) as $a) {
            $clocked[(int) $a['user_id'] . '|' . $a['shift_date']][] = $a;
        }

        $rows = [];
        foreach (array_unique([...array_keys($planned), ...array_keys($clocked)]) as $key) {
            [$uid, $date] = explode('|', (string) $key, 2);
            foreach ($this->match($planned[$key] ?? [], $clocked[$key] ?? []) as [$shift, $attendance]) {
                $rows[] = $this->row((int) $uid, $date, $shift, $attendance, $now);
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a['user_id'], $a['date'], self::sortTime($a)] <=> [$b['user_id'], $b['date'], self::sortTime($b)]);

        $totals = [];
        $counts = [];
        foreach ($rows as $r) {
            $t = $totals[$r['user_id']] ?? ['planned_minutes' => 0, 'worked_minutes' => 0, 'anomalies' => 0];
            $t['planned_minutes'] += $r['planned_minutes'];
            $t['worked_minutes']  += $r['worked_minutes'];
            if (array_intersect($r['flags'], self::ANOMALIES) !== []) {
                $t['anomalies']++;
            }
            $totals[$r['user_id']] = $t;
            foreach ($r['flags'] as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals, 'counts' => $counts, 'fingerprint' => self::fingerprint($rows)];
    }

    /**
     * Empreinte des données de la semaine (shifts et pointages rapprochés) : une semaine validée dont l'empreinte a
     * changé a été modifiée depuis sa validation.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fingerprint(array $rows): string
    {
        $parts = array_map(static fn(array $r): string => implode(',', [
            $r['user_id'], $r['date'],
            $r['shift']['id'] ?? '', $r['shift']['start_time'] ?? '', $r['shift']['end_time'] ?? '', $r['shift']['pause_minutes'] ?? '',
            $r['attendance']['id'] ?? '', $r['attendance']['clock_in_time'] ?? '', $r['attendance']['clock_out_time'] ?? '',
        ]), $rows);
        return sha1(implode(';', $parts));
    }

    /**
     * Paires (shift, pointage) d'un employé sur un jour : chaque pointage va au shift libre dont le début est le plus
     * proche de son arrivée ; un shift sans pointage ou un pointage sans shift forme une paire incomplète.
     *
     * @param list<array<string, mixed>> $shifts
     * @param list<array<string, mixed>> $attendances
     * @return list<array{0: ?array<string, mixed>, 1: ?array<string, mixed>}>
     */
    private function match(array $shifts, array $attendances): array
    {
        usort($attendances, static fn(array $a, array $b): int => strcmp((string) $a['clock_in_time'], (string) $b['clock_in_time']));
        $pairs = [];
        $free  = $shifts;
        foreach ($attendances as $a) {
            $in   = strtotime((string) $a['clock_in_time']);
            $best = null;
            foreach ($free as $i => $s) {
                $gap = abs($in - (int) strtotime(self::startsAt($s)));
                if ($best === null || $gap < $best[1]) {
                    $best = [$i, $gap];
                }
            }
            if ($best !== null) {
                $pairs[] = [$free[$best[0]], $a];
                unset($free[$best[0]]);
            } else {
                $pairs[] = [null, $a];
            }
        }
        foreach ($free as $s) {
            $pairs[] = [$s, null];
        }
        return $pairs;
    }

    /**
     * @param ?array<string, mixed> $shift
     * @param ?array<string, mixed> $attendance
     * @return array{user_id: int, date: string, shift: ?array<string, mixed>, attendance: ?array<string, mixed>,
     *               planned_minutes: int, worked_minutes: int, late_minutes: int, early_minutes: int,
     *               overtime_minutes: int, flags: list<string>}
     */
    private function row(int $userId, string $date, ?array $shift, ?array $attendance, \DateTimeImmutable $now): array
    {
        $pause   = (int) ($shift['pause_minutes'] ?? 0);
        $row = [
            'user_id' => $userId, 'date' => $date, 'shift' => $shift, 'attendance' => $attendance,
            'planned_minutes' => 0, 'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0,
            'flags' => [],
        ];
        $nowTs = $now->getTimestamp();

        $start = $end = null;
        if ($shift !== null) {
            $start = (int) strtotime(self::startsAt($shift));
            $end   = (int) strtotime(self::endsAt($shift));
            $row['planned_minutes'] = max(0, intdiv($end - $start, 60) - $pause);
        }

        if ($attendance === null) {
            // Shift sans pointage : absence une fois la tolérance passée, sinon à venir.
            $row['flags'][] = $start !== null && $nowTs > $start + self::GRACE_MINUTES * 60 ? self::NO_SHOW : self::UPCOMING;
            return $row;
        }

        $in  = (int) strtotime((string) $attendance['clock_in_time']);
        $out = !empty($attendance['clock_out_time']) ? (int) strtotime((string) $attendance['clock_out_time']) : null;
        if ($shift === null) {
            $row['flags'][] = self::UNPLANNED;
        }

        if ($out === null) {
            // Sortie non pointée : oubliée une fois le shift terminé (ou 12 h après l'arrivée hors planning).
            $deadline = $end ?? ($in + 12 * 3600);
            $row['flags'][] = $nowTs > $deadline + self::GRACE_MINUTES * 60 ? self::MISSING_CLOCK_OUT : self::IN_PROGRESS;
        } else {
            $row['worked_minutes'] = max(0, intdiv(max(0, $out - $in), 60) - $pause);
        }

        if ($start !== null && $end !== null) {
            $late = intdiv($in - $start, 60);
            if ($late > self::GRACE_MINUTES) {
                $row['late_minutes'] = $late;
                $row['flags'][] = self::LATE;
            }
            if ($out !== null) {
                $early = intdiv($end - $out, 60);
                $over  = intdiv($out - $end, 60);
                if ($early > self::GRACE_MINUTES) {
                    $row['early_minutes'] = $early;
                    $row['flags'][] = self::EARLY_LEAVE;
                } elseif ($over > self::GRACE_MINUTES) {
                    $row['overtime_minutes'] = $over;
                    $row['flags'][] = self::OVERTIME;
                }
            }
        }

        if ($row['flags'] === []) {
            $row['flags'][] = self::OK;
        }
        return $row;
    }

    /** @param array<string, mixed> $shift */
    private static function startsAt(array $shift): string
    {
        return !empty($shift['starts_at']) ? (string) $shift['starts_at'] : $shift['shift_date'] . ' ' . substr((string) $shift['start_time'], 0, 5) . ':00';
    }

    /** @param array<string, mixed> $shift */
    private static function endsAt(array $shift): string
    {
        if (!empty($shift['ends_at'])) {
            return (string) $shift['ends_at'];
        }
        $day = !empty($shift['cross_midnight']) ? date('Y-m-d', (int) strtotime($shift['shift_date'] . ' +1 day')) : $shift['shift_date'];
        return $day . ' ' . substr((string) $shift['end_time'], 0, 5) . ':00';
    }

    /** @param array<string, mixed> $row */
    private static function sortTime(array $row): string
    {
        return $row['shift'] !== null ? self::startsAt($row['shift']) : (string) ($row['attendance']['clock_in_time'] ?? '');
    }
}
