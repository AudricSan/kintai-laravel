<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Export de l'horaire d'un employé dans un magasin (JSON, CSV). L'ICS est produit par IcalService, avec les mêmes
 * shifts : ce service ne fait que choisir la période et mettre les shifts à plat.
 *
 * Pur : il ne lit ni la requête ni la base, le contrôleur lui fournit les shifts, le magasin et les types.
 */
final class ScheduleExportService
{
    public const FORMATS = ['json', 'csv', 'ics'];

    /** Période maximale exportable, pour borner la taille du fichier. */
    public const MAX_DAYS = 366;

    private const CSV_COLUMNS = ['date', 'start_time', 'end_time', 'ends_next_day', 'break_minutes', 'net_minutes', 'shift_type', 'store', 'notes'];

    /**
     * Borne la période demandée : dates invalides ou absentes → aujourd'hui et +90 jours, bornes inversées remises
     * dans l'ordre, durée plafonnée à MAX_DAYS.
     *
     * @return array{from: string, to: string}
     */
    public function period(?string $from, ?string $to, string $today): array
    {
        $start = $this->validDate($from) ?? $today;
        $end   = $this->validDate($to) ?? date('Y-m-d', strtotime($start . ' +90 days'));

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }
        $limit = date('Y-m-d', strtotime($start . ' +' . self::MAX_DAYS . ' days'));
        if ($end > $limit) {
            $end = $limit;
        }

        return ['from' => $start, 'to' => $end];
    }

    /**
     * Shifts de la période, dans l'ordre chronologique, sans ceux qui ont été supprimés.
     *
     * @param list<array<string, mixed>> $shifts
     * @return list<array<string, mixed>>
     */
    public function select(array $shifts, string $from, string $to): array
    {
        $kept = array_values(array_filter($shifts, static function (array $s) use ($from, $to): bool {
            $date = (string) ($s['shift_date'] ?? '');
            return empty($s['deleted_at']) && $date >= $from && $date <= $to;
        }));

        usort($kept, static fn(array $a, array $b): int => [$a['shift_date'] ?? '', $a['start_time'] ?? ''] <=> [$b['shift_date'] ?? '', $b['start_time'] ?? '']);

        return $kept;
    }

    /**
     * Lignes à plat, identiques pour le JSON et le CSV.
     *
     * @param list<array<string, mixed>> $shifts          Résultat de select()
     * @param array<int, array<string, mixed>> $types     Types de shift par identifiant
     * @return list<array{date: string, start_time: string, end_time: string, ends_next_day: bool, break_minutes: int, net_minutes: int, shift_type: string, store: string, notes: string}>
     */
    public function rows(array $shifts, array $store, array $types): array
    {
        $rows = [];
        foreach ($shifts as $s) {
            $start = substr((string) ($s['start_time'] ?? '00:00'), 0, 5);
            $end   = substr((string) ($s['end_time'] ?? '00:00'), 0, 5);
            $next  = !empty($s['cross_midnight']);
            $break = max(0, (int) ($s['pause_minutes'] ?? 0));

            $rows[] = [
                'date'          => (string) ($s['shift_date'] ?? ''),
                'start_time'    => $start,
                'end_time'      => $end,
                'ends_next_day' => $next,
                'break_minutes' => $break,
                'net_minutes'   => max(0, $this->minutes($start, $end, $next) - $break),
                'shift_type'    => (string) ($types[(int) ($s['shift_type_id'] ?? 0)]['name'] ?? ''),
                'store'         => (string) ($store['name'] ?? ''),
                'notes'         => (string) ($s['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows Résultat de rows()
     * @return array<string, mixed>
     */
    public function json(array $rows, array $store, string $from, string $to): array
    {
        return [
            'store'  => ['id' => (int) ($store['id'] ?? 0), 'name' => (string) ($store['name'] ?? ''), 'timezone' => (string) ($store['timezone'] ?? '')],
            'from'   => $from,
            'to'     => $to,
            'count'  => count($rows),
            'shifts' => $rows,
        ];
    }

    /**
     * CSV UTF-8 avec BOM (Excel y reconnaît l'UTF-8), séparateur « ; » (Excel francophone), fins de ligne CRLF.
     *
     * @param list<array<string, mixed>> $rows Résultat de rows()
     */
    public function csv(array $rows): string
    {
        $lines = [implode(';', self::CSV_COLUMNS)];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(static fn(string $col): string => self::csvCell($row[$col] ?? ''), self::CSV_COLUMNS));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Une cellule CSV. Un texte qui commence par = + - @ (ou une tabulation) serait lu comme une formule par un tableur : on le
     * fait précéder d'une apostrophe. Les nombres (dont négatifs) et les horaires ne sont pas touchés.
     */
    public static function csvCell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
        if (!is_int($value) && preg_match('/^[=+\-@\t]/', $text) === 1) {
            $text = "'" . $text;
        }
        if (preg_match('/[;"]/', $text) === 1) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    private function minutes(string $start, string $end, bool $nextDay): int
    {
        $toMinutes = static fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $diff = $toMinutes($end) - $toMinutes($start);

        return $nextDay || $diff < 0 ? $diff + 1440 : $diff;
    }

    private function validDate(?string $date): ?string
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }
}
