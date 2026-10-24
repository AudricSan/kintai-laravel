<?php

declare(strict_types=1);

namespace kintai\UI\Components;

final class Timeline implements ComponentInterface
{
    use Traits\HasAttributes;

    private array $days = [];
    private array $shiftsByDateUser = [];
    private array $usersMap = [];
    private array $userColorMap = [];
    private array $typesMap = [];
    private int $myUserId = 0;
    private string $today = '';
    private int $tStart = 360;   // 06:00 in minutes
    private int $tTotal = 1440;  // 24 hours in minutes
    private int $minStaffDay = 0;
    private int $minShiftMin = 0;
    private int $maxShiftMin = 0;
    private int $lowStaffStart = -1;
    private int $lowStaffEnd = -1;

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public function days(array $days): self                    { $this->days = $days; return $this; }
    public function shiftsByDateUser(array $data): self        { $this->shiftsByDateUser = $data; return $this; }
    public function usersMap(array $map): self                 { $this->usersMap = $map; return $this; }
    public function userColorMap(array $map): self             { $this->userColorMap = $map; return $this; }
    public function typesMap(array $map): self                 { $this->typesMap = $map; return $this; }
    public function myUserId(int $id): self                    { $this->myUserId = $id; return $this; }
    public function today(string $date): self                  { $this->today = $date; return $this; }
    public function tStart(int $min): self                     { $this->tStart = $min; return $this; }
    public function tTotal(int $min): self                     { $this->tTotal = $min; return $this; }
    public function minStaffDay(int $n): self                  { $this->minStaffDay = $n; return $this; }
    public function minShiftMin(int $n): self                  { $this->minShiftMin = $n; return $this; }
    public function maxShiftMin(int $n): self                  { $this->maxShiftMin = $n; return $this; }
    public function lowStaffRange(int $start, int $end): self  { $this->lowStaffStart = $start; $this->lowStaffEnd = $end; return $this; }

    public function render(): string
    {
        if (empty($this->days)) {
            return '';
        }

        $html = '<div' . $this->buildAttributes(['sd-timeline']) . '>';
        foreach ($this->days as $day) {
            $html .= $this->renderDay($day);
        }
        $html .= '</div>';
        return $html;
    }

    private function renderDay(\DateTimeImmutable $day): string
    {
        $dateStr = $day->format('Y-m-d');
        $isToday = $dateStr === $this->today;
        $dayName = __(strtolower($day->format('l')));

        $dayShifts = [];
        foreach ($this->shiftsByDateUser as $uid => $userShiftsByDate) {
            foreach ($userShiftsByDate[$dateStr] ?? [] as $s) {
                $tid = (int) ($s['shift_type_id'] ?? 0);
                $dayShifts[] = array_merge($s, [
                    '_uid'       => $uid,
                    '_name'      => $this->usersMap[$uid] ?? ('#' . $uid),
                    '_is_me'     => $uid === $this->myUserId,
                    '_color'     => $this->userColorMap[$uid] ?? '#6366f1',
                    '_type_name' => $this->typesMap[$tid]['name'] ?? 'Shift',
                ]);
            }
        }

        usort($dayShifts, function ($a, $b) {
            $sm = fn($s) => (($v = $this->atMin($s['start_time'] ?? '00:00')) < $this->tStart) ? $v + 1440 : $v;
            return $sm($a) - $sm($b);
        });

        $lanes      = $this->atLanes($dayShifts);
        $shiftCount = count($dayShifts);

        $diag        = $this->computeDiagnostics($dayShifts, $dateStr);
        $hasIssues   = $diag['has_issues'];
        $issueCount  = $diag['issue_count'];
        $staffCount  = $diag['staff_count'];
        $peakConcurrent = $diag['peak_concurrent'];
        $headerHours = $this->buildHeaderHours();

        $cardClass = 'card sd-day-card tl-day-card' . ($isToday ? ' tl-day-card--today' : '');
        $diagJson  = htmlspecialchars(json_encode([
            'staff'               => $staffCount,
            'peak'                => $peakConcurrent,
            'min_staff'           => $this->minStaffDay,
            'understaffed'        => $diag['understaffed'],
            'understaffed_ranges' => $diag['understaffed_ranges'],
            'conflicts'           => $diag['conflicts'],
            'short'               => $diag['short_shifts'],
            'long'                => $diag['long_shifts'],
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

        $html = '<div class="' . htmlspecialchars($cardClass) . '" data-date="' . htmlspecialchars($dateStr) . '">';
        $html .= '<div class="tl-scroll"><div class="tl-scroll-inner">';

        // Day header
        $html .= '<div class="tl-day-header">';
        $html .= '<div class="tl-day-col">';
        $html .= '<span class="tl-day-name--' . ($isToday ? 'today' : 'normal') . '">' . htmlspecialchars($dayName) . '</span>';
        $html .= '<span class="tl-day-sub">' . $day->format('d M') . '</span>';
        if ($isToday) {
            $html .= Badge::make('Today')->active()->sm()->render();
        }
        $html .= '</div>';

        $html .= '<span class="tl-day-stats">'
            . $shiftCount . ' shift' . ($shiftCount !== 1 ? 's' : '')
            . (!empty($dayShifts) ? ' &middot; ' . $staffCount . ' staff' : '')
            . ($this->minStaffDay > 0 && !empty($dayShifts)
                ? ' &middot; Peak ' . $peakConcurrent . '/' . $this->minStaffDay
                : '')
            . '</span>';

        if ($hasIssues) {
            $html .= '<span class="tl-diag-badge sd-diag-badge" data-diag="' . $diagJson . '">⚠ ' . $issueCount . '</span>';
        } elseif (!empty($dayShifts)) {
            $html .= '<span class="tl-diag-ok" title="No issues detected">✓</span>';
        }

        $html .= '</div>'; // tl-day-header

        // Gantt body
        $html .= '<div class="tl-gantt-body">';

        // Hours axis
        $html .= '<div class="tl-hours-axis">';
        foreach ($headerHours as $h) {
            $html .= '<div class="tl-hours-cell">' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . '</div>';
        }
        $html .= '</div>';

        if (empty($lanes)) {
            $html .= '<div class="tl-empty-lane"><span class="tl-empty-text">—</span></div>';
        } else {
            foreach ($lanes as $li => $laneShifts) {
                $html .= '<div class="sd-lane tl-lane' . ($li > 0 ? ' tl-lane--mt' : '') . '">';
                // Grid lines
                for ($i = 0; $i <= 24; $i++) {
                    $op = $i % 6 === 0 ? '.5' : '.2';
                    $html .= '<div class="tl-grid-line ' . style_class(['left' => number_format($i / 24 * 100, 5) . '%', 'opacity' => $op]) . '"></div>';
                }
                // Shift bars
                foreach ($laneShifts as $sh) {
                    $html .= $this->renderShiftBar($sh);
                }
                $html .= '</div>';
            }
        }

        // Create zone
        $html .= '<div class="sd-create-zone">';
        $html .= '<span class="sd-create-zone__hint"><span class="sd-create-zone__plus">+</span> Click & drag to create</span>';
        $html .= '</div>';

        $html .= '</div>'; // tl-gantt-body
        $html .= '</div></div>'; // tl-scroll
        $html .= '</div>'; // card

        return $html;
    }

    private function renderShiftBar(array $sh): string
    {
        $sm = $sh['_sm'] ?? $this->atMin($sh['start_time'] ?? '00:00');
        $em = $sh['_em'] ?? $this->atMin($sh['end_time'] ?? '00:00');
        if (!empty($sh['cross_midnight']) || $em <= $sm) $em += 1440;
        if ($sm < $this->tStart) { $sm += 1440; $em += 1440; }

        $left  = ($sm - $this->tStart) / $this->tTotal * 100;
        $width = ($em - $sm) / $this->tTotal * 100;
        $left  = max(0, min(100, $left));
        $width = max(0.4, min(100 - $left, $width));

        $color     = $sh['_color'] ?? '#6366f1';
        $isMe      = !empty($sh['_is_me']);
        $bg        = $isMe ? $color : $color . '70';
        $border    = $isMe ? "2px solid {$color}" : "1px solid {$color}aa";
        $textColor = $this->wcagContrastColor($color);

        $shPause    = (int) ($sh['pause_minutes'] ?? 0);
        $shNetMin   = ($em - $sm) - $shPause;
        $shHoursLabel = intdiv($shNetMin, 60) . 'h' . str_pad($shNetMin % 60, 2, '0', STR_PAD_LEFT)
            . ($shPause > 0 ? ' (pause ' . $shPause . 'min)' : '');

        $tooltip = htmlspecialchars(
            ($sh['_name'] ?? '') . ' &middot; ' . $this->atFmt($sm) . '–' . $this->atFmt($em)
            . ' (' . ($sh['_type_name'] ?? 'Shift') . ')'
            . "\n" . $shHoursLabel
        );

        $html = '<div title="' . $tooltip . '"'
            . ' data-on-click="sdModalOpen" data-args=\'["@this"]\''
            . ' data-id="' . ((int) ($sh['id'] ?? 0)) . '"'
            . ' data-date="' . htmlspecialchars($sh['shift_date'] ?? '') . '"'
            . ' data-start="' . $this->atFmt($sm) . '"'
            . ' data-end="' . $this->atFmt($em) . '"'
            . ' data-name="' . htmlspecialchars($sh['_name'] ?? '') . '"'
            . ' data-type="' . htmlspecialchars($sh['_type_name'] ?? '') . '"'
            . ' data-notes="' . htmlspecialchars($sh['notes'] ?? '') . '"'
            . ' data-color="' . htmlspecialchars($color) . '"'
            . ' data-hours="' . htmlspecialchars($shHoursLabel) . '"'
            . ' data-width-pct="' . number_format($width, 5) . '"'
            . ' data-draggable="1"'
            . ' class="tl-bar ' . style_class([
                'left' => number_format($left, 5) . '%', 'width' => number_format($width, 5) . '%',
                'background' => $bg, 'border' => $border, 'color' => $textColor,
            ]) . '">';
        if ($width > 4) {
            $html .= '<span class="tl-bar-label tl-bar-label--main">' . $this->atFmt($sm) . '–' . $this->atFmt($em) . '</span>';
        }
        if ($width > 9) {
            $html .= '<span class="tl-bar-label tl-bar-label--sub">' . htmlspecialchars($sh['_name'] ?? '') . '</span>';
        }
        $html .= '</div>';
        return $html;
    }

    private function computeDiagnostics(array $dayShifts, string $dateStr): array
    {
        $diag = [];
        foreach ($dayShifts as $_sh) {
            $_sm = $this->atMin($_sh['start_time'] ?? '00:00');
            $_em = $this->atMin($_sh['end_time']   ?? '00:00');
            if (!empty($_sh['cross_midnight']) || $_em <= $_sm) $_em += 1440;
            if ($_sm < $this->tStart) { $_sm += 1440; $_em += 1440; }
            $diag[] = array_merge($_sh, ['_sm' => $_sm, '_em' => $_em]);
        }

        $staffCount  = count(array_unique(array_column($diag, '_uid')));
        $hourlyStaff = array_fill(0, 24, 0);
        foreach ($diag as $_sh) {
            for ($_h = 0; $_h < 24; $_h++) {
                if ($_sh['_sm'] < ($_h + 1) * 60 && $_sh['_em'] > $_h * 60) {
                    $hourlyStaff[$_h]++;
                }
            }
        }
        $peakConcurrent = max(array_merge([0], $hourlyStaff));

        $conflicts   = [];
        $shortShifts = [];
        $longShifts  = [];

        $byUser = [];
        foreach ($diag as $_sh) $byUser[$_sh['_uid']][] = $_sh;
        foreach ($byUser as $_uid => $_ushifts) {
            usort($_ushifts, fn($a, $b) => $a['_sm'] - $b['_sm']);
            for ($_i = 1; $_i < count($_ushifts); $_i++) {
                if ($_ushifts[$_i]['_sm'] < $_ushifts[$_i - 1]['_em']) {
                    $conflicts[] = ($this->usersMap[$_uid] ?? ('#' . $_uid))
                        . ' : ' . $this->atFmt($_ushifts[$_i - 1]['_sm']) . '–' . $this->atFmt($_ushifts[$_i - 1]['_em'])
                        . ' / ' . $this->atFmt($_ushifts[$_i]['_sm']) . '–' . $this->atFmt($_ushifts[$_i]['_em']);
                }
            }
        }

        foreach ($diag as $_sh) {
            $_dur = $_sh['_em'] - $_sh['_sm'];
            if ($this->minShiftMin > 0 && $_dur < $this->minShiftMin) {
                $shortShifts[] = $_sh['_name'] . ' ' . $this->atFmt($_sh['_sm']) . '–' . $this->atFmt($_sh['_em'])
                    . ' (' . intdiv($_dur, 60) . 'h' . str_pad($_dur % 60, 2, '0', STR_PAD_LEFT) . ')';
            }
            if ($this->maxShiftMin > 0 && $_dur > $this->maxShiftMin) {
                $longShifts[] = $_sh['_name'] . ' ' . $this->atFmt($_sh['_sm']) . '–' . $this->atFmt($_sh['_em'])
                    . ' (' . intdiv($_dur, 60) . 'h' . str_pad($_dur % 60, 2, '0', STR_PAD_LEFT) . ')';
            }
        }

        $lowExempt = function (int $h): bool {
            if ($this->lowStaffStart < 0 || $this->lowStaffEnd < 0) return false;
            if ($this->lowStaffStart < $this->lowStaffEnd) return $h >= $this->lowStaffStart && $h < $this->lowStaffEnd;
            return $h >= $this->lowStaffStart || $h < $this->lowStaffEnd;
        };

        $understaffedRanges = [];
        if ($this->minStaffDay > 0) {
            $inRange = false; $rStart = 0; $rMin = 0;
            for ($_h = 0; $_h < 24; $_h++) {
                $_cnt = $hourlyStaff[$_h];
                if ($_cnt > 0 && $_cnt < $this->minStaffDay && !$lowExempt($_h)) {
                    if (!$inRange) { $inRange = true; $rStart = $_h; $rMin = $_cnt; }
                    else { $rMin = min($rMin, $_cnt); }
                } else {
                    if ($inRange) {
                        $understaffedRanges[] = sprintf('%02d:00–%02d:00 (%d/%d)', $rStart, $_h, $rMin, $this->minStaffDay);
                        $inRange = false;
                    }
                }
            }
            if ($inRange) {
                $understaffedRanges[] = sprintf('%02d:00–24:00 (%d/%d)', $rStart, $rMin, $this->minStaffDay);
            }
        }

        $understaffed = !empty($understaffedRanges);
        $hasIssues    = !empty($conflicts) || !empty($shortShifts) || !empty($longShifts) || $understaffed;

        return [
            'staff_count'         => $staffCount,
            'peak_concurrent'     => $peakConcurrent,
            'conflicts'           => $conflicts,
            'short_shifts'        => $shortShifts,
            'long_shifts'         => $longShifts,
            'understaffed'        => $understaffed,
            'understaffed_ranges' => $understaffedRanges,
            'has_issues'          => $hasIssues,
            'issue_count'         => count($conflicts) + count($shortShifts) + count($longShifts) + ($understaffed ? 1 : 0),
        ];
    }

    private function buildHeaderHours(): array
    {
        $hours = [];
        for ($i = 0; $i < 24; $i++) {
            $hours[] = ($this->tStart + $i * 60) / 60 % 24;
        }
        return $hours;
    }

    private function atLanes(array $rawShifts): array
    {
        $normalized = [];
        foreach ($rawShifts as $s) {
            $sm = $s['_sm'] ?? $this->atMin($s['start_time'] ?? '00:00');
            $em = $s['_em'] ?? $this->atMin($s['end_time'] ?? '00:00');
            if (!empty($s['cross_midnight']) || $em <= $sm) $em += 1440;
            if ($sm < $this->tStart) { $sm += 1440; $em += 1440; }
            $normalized[] = array_merge($s, ['_sm' => $sm, '_em' => $em]);
        }

        usort($normalized, fn($a, $b) => ($a['_sm'] <=> $b['_sm']) ?: ($b['_em'] - $a['_em']));

        $lanes = [];
        foreach ($normalized as $s) {
            $placed = false;
            foreach ($lanes as &$lane) {
                $last = end($lane);
                if ($s['_sm'] >= $last['_em']) {
                    $lane[] = $s;
                    $placed = true;
                    break;
                }
            }
            unset($lane);
            if (!$placed) {
                $lanes[] = [$s];
            }
        }
        return $lanes;
    }

    private function atMin(string $t): int
    {
        $parts = explode(':', $t);
        return (int) ($parts[0] ?? 0) * 60 + (int) ($parts[1] ?? 0);
    }

    private function atFmt(int $min): string
    {
        return str_pad((string) intdiv($min % 1440, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT);
    }

    private function wcagContrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 && strlen($hex) !== 3) return '#ffffff';
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return $lum > 0.5 ? '#000000' : '#ffffff';
    }
}
