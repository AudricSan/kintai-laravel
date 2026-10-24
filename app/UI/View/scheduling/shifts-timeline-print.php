<?php
/**
 * Planning Timeline — Document d'impression (HTML autonome, sans layout)
 *
 * @var \DateTimeImmutable[] $days
 * @var string               $period_mode        'week' | '3days'
 * @var array                $shifts_by_date_user date → uid → shift[]
 * @var int[]                $all_user_ids
 * @var array                $users_map           id → nom
 * @var array                $user_color_map      id → couleur hex|null
 * @var array                $types_map           id → shift_type
 * @var string               $today
 * @var int                  $filter_store_id
 * @var array                $stores_map          id → nom
 * @var string               $BASE_URL
 */

// ── Helpers ──────────────────────────────────────────────────────────────────
// Garde : la vue peut être rendue deux fois dans un même processus ; une seconde déclaration de ces fonctions
// globales serait une erreur fatale (« Cannot redeclare »).
if (!function_exists('ptMin')) {
    function ptMin(string $t): int
    {
        $p = explode(':', substr($t, 0, 5));
        return (int) ($p[0] ?? 0) * 60 + (int) ($p[1] ?? 0);
    }
    function ptFmt(string $t): string { return substr($t, 0, 5); }

    // Grouper les shifts en lanes sans chevauchement (identique au comportement web)
    function ptLanes(array $shifts, int $tStart): array
    {
        $lanes = []; $ends = [];
        foreach ($shifts as $sh) {
            $sm = ptMin($sh['start_time'] ?? '00:00');
            $em = ptMin($sh['end_time']   ?? '00:00');
            if (!empty($sh['cross_midnight']) || $em <= $sm) $em += 1440;
            if ($sm < $tStart) { $sm += 1440; $em += 1440; }
            $sh['_sm'] = $sm; $sh['_em'] = $em;
            $placed = false;
            foreach ($ends as $li => $lEnd) {
                if ($lEnd <= $sm) { $lanes[$li][] = $sh; $ends[$li] = $em; $placed = true; break; }
            }
            if (!$placed) { $lanes[] = [$sh]; $ends[] = $em; }
        }
        return $lanes;
    }
}

$T_START = 6 * 60;
$T_TOTAL = 24 * 60;

$_palette = ['#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6','#8b5cf6','#f97316','#06b6d4','#ec4899','#84cc16'];
$userColorMap = [];
foreach ($all_user_ids as $i => $uid) {
    $userColorMap[$uid] = $user_color_map[$uid] ?? $_palette[$i % count($_palette)];
}

$storeName   = $stores_map[$filter_store_id] ?? '';
$firstDay    = $days[0];
$lastDay     = $days[count($days) - 1];
$periodLabel = $period_mode === 'week'
    ? __('period_week_label', ['start' => $firstDay->format('d M'), 'end' => $lastDay->format('d M Y')])
    : __('period_3days_label', ['start' => $firstDay->format('d M'), 'end' => $lastDay->format('d M Y')]);

$ganttHours = [];
for ($h = 6; $h < 30; $h++) $ganttHours[] = $h % 24;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= __('planning') ?> — <?= __('timeline') ?> — <?= htmlspecialchars($storeName) ?></title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/pdf/pdf-timeline.css">
    <!-- @page @bottom-center dynamique (date PHP) — ne pas externaliser -->
    <style nonce="<?= csp_nonce() ?>">
        @page {
            @bottom-center {
                content: "<?= __('print_footer_disclaimer', ['date' => date('d/m/Y H:i')]) ?>";
                font-family: Arial, Helvetica, sans-serif;
                font-size: 6pt;
                color: #999;
            }
        }
    </style>
</head>
<body>

<div class="pt-toolbar">
    <button data-on-click="@print">🖨 <?= __('print_timeline') ?></button>
    <a href="#" data-on-click="@close">✕ <?= __('close') ?></a>
    <span class="pt-toolbar__hint"><?= __('print_hint') ?></span>
</div>

<div class="pt-page">

    <div class="pt-header">
        <div class="pt-brand">Kintai <small><?= htmlspecialchars($storeName) ?></small></div>
        <div class="pt-doc-info">
            <strong><?= __('planning') ?> — <?= __('timeline') ?></strong>
            <span><?= htmlspecialchars($periodLabel) ?></span>
            <span><?= __('payslip_issued_on') ?> <?= date('d/m/Y') ?></span>
        </div>
    </div>

    <?php if (empty($all_user_ids)): ?>
        <p class="pt-empty-state"><?= __('no_store_found') ?></p>
    <?php else: ?>

    <table class="pt-gantt-table">
        <colgroup>
            <?php foreach ($ganttHours as $_): ?><col class="pt-col-hour"><?php endforeach; ?>
        </colgroup>
        <tbody>

        <?php foreach ($days as $dayIdx => $day): ?>
        <?php
        $dateStr = $day->format('Y-m-d');
        $isToday = $dateStr === $today;
        $dayName = __(strtolower($day->format('l')));

        // Collecte et enrichissement des shifts
        $dayShifts = [];
        foreach ($all_user_ids as $uid) {
            foreach ($shifts_by_date_user[$dateStr][$uid] ?? [] as $s) {
                $tid = (int) ($s['shift_type_id'] ?? 0);
                $dayShifts[] = array_merge($s, [
                    '_uid'   => $uid,
                    '_name'  => $users_map[$uid] ?? ('#' . $uid),
                    '_color' => $userColorMap[$uid] ?? '#6366f1',
                ]);
            }
        }

        // Tri par heure de début (comme la timeline web)
        usort($dayShifts, fn($a, $b) => strcmp($a['start_time'] ?? '', $b['start_time'] ?? ''));

        $shiftCount = count($dayShifts);
        $staffCount = count(array_unique(array_column($dayShifts, '_uid')));

        // Peak concurrent
        $_hourlyStaff = array_fill(0, 24, 0);
        foreach ($dayShifts as $_sh) {
            $sm = ptMin($_sh['start_time'] ?? '00:00');
            $em = ptMin($_sh['end_time']   ?? '00:00');
            if (!empty($_sh['cross_midnight']) || $em <= $sm) $em += 1440;
            if ($sm < $T_START) { $sm += 1440; $em += 1440; }
            for ($h = 0; $h < 24; $h++) {
                $hStart = $T_START + $h * 60;
                if ($sm < $hStart + 60 && $em > $hStart) $_hourlyStaff[$h]++;
            }
        }
        $_peak = max(array_merge([0], $_hourlyStaff));

        // Conflits
        $_hasConflicts = false;
        $_byUser = [];
        foreach ($dayShifts as $_sh) {
            $sm = ptMin($_sh['start_time'] ?? '00:00');
            $em = ptMin($_sh['end_time']   ?? '00:00');
            if (!empty($_sh['cross_midnight']) || $em <= $sm) $em += 1440;
            if ($sm < $T_START) { $sm += 1440; $em += 1440; }
            $_byUser[$_sh['_uid']][] = ['sm' => $sm, 'em' => $em];
        }
        foreach ($_byUser as $_ushifts) {
            usort($_ushifts, fn($a, $b) => $a['sm'] - $b['sm']);
            for ($_i = 1; $_i < count($_ushifts); $_i++) {
                if ($_ushifts[$_i]['sm'] < $_ushifts[$_i - 1]['em']) { $_hasConflicts = true; break 2; }
            }
        }

        // Calcul des lanes
        $lanes = ptLanes($dayShifts, $T_START);
        ?>

        <?php if ($dayIdx > 0): ?>
        <tr class="pt-tr-spacer"><td colspan="24"></td></tr>
        <?php endif; ?>

        <!-- Ligne en-tête du jour -->
        <tr class="pt-tr-day <?= $isToday ? 'pt-tr-day--today' : '' ?>">
            <td colspan="24" class="pt-td-day">
                <span class="pt-day-label"><?= $dayName ?> <?= $day->format('d M Y') ?></span>
                <?php if ($_hasConflicts): ?>
                    &nbsp;<span class="pt-badge-warn">⚠ <?= __('conflicts_detected') ?></span>
                <?php endif; ?>
            </td>
        </tr>

        <!-- Répétition de l'axe des heures sous chaque jour -->
        <tr class="pt-tr-hours-repeat">
            <?php foreach ($ganttHours as $gh): ?>
                <th class="pt-th-hour-repeat <?= $gh % 6 === 0 ? 'pt-th-hour--major' : '' ?>">
                    <?= str_pad((string) $gh, 2, '0', STR_PAD_LEFT) ?>
                </th>
            <?php endforeach; ?>
        </tr>

        <?php if (empty($dayShifts)): ?>
            <tr><td colspan="24" class="pt-td-empty">— <?= __('no_shift_this_day') ?> —</td></tr>
        <?php else: ?>

            <!-- Une ligne par lane -->
            <?php foreach ($lanes as $lane): ?>
            <tr class="pt-tr-lane">
                <td colspan="24" class="pt-td-bars">
                    <div class="pt-lane">
                        <?php foreach ($lane as $sh): ?>
                        <?php
                        $left  = ($sh['_sm'] - $T_START) / $T_TOTAL * 100;
                        $width = ($sh['_em'] - $sh['_sm']) / $T_TOTAL * 100;
                        $left  = max(0, min(100, $left));
                        $width = max(0.8, min(100 - $left, $width));
                        $color = $sh['_color'];
                        ?>
                        <div class="pt-bar <?= style_class(['left' => number_format($left, 4) . '%', 'width' => number_format($width, 4) . '%', 'background' => $color]) ?>">
                            <?php if ($width > 2.5): ?>
                                <span class="pt-bar-name"><?= htmlspecialchars($sh['_name']) ?></span>
                            <?php endif; ?>
                            <?php if ($width > 7): ?>
                                <span class="pt-bar-time"><?= ptFmt($sh['start_time'] ?? '') ?>–<?= ptFmt($sh['end_time'] ?? '') ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>

        <?php endif; ?>
        <?php endforeach; ?>

        </tbody>
    </table>

    <!-- Légende employés -->
    <div class="pt-legend">
        <?php foreach ($all_user_ids as $uid): ?>
            <span class="pt-legend-item">
                <span class="pt-legend-bar <?= style_class(['background' => $userColorMap[$uid] ?? '#6366f1']) ?>"></span>
                <span><?= htmlspecialchars($users_map[$uid] ?? ('#' . $uid)) ?></span>
            </span>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <div class="pt-footer">
        <?= __('payslip_footer', ['date' => date('d/m/Y H:i')]) ?>
    </div>

</div>

<?php if ($autoprint ?? false): ?><meta name="autoprint" content="1"><?php endif; ?>
<?= inline_styles() ?>
<script src="<?= $BASE_URL ?>/assets/js/modules/csp-actions.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/autoprint.js"></script>
</body>
</html>
