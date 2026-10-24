<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;
use kintai\UI\Utils\TimelineHelpers;

/** @var \DateTimeImmutable[] $days */
/** @var string               $period_mode        'week' | '3days' */
/** @var array                $shifts_by_date_user date → uid → shift[] */
/** @var int[]                $all_user_ids */
/** @var array                $users_map           id → nom */
/** @var array                $user_color_map      id → couleur hex|null */
/** @var array                $types_map           id → shift_type */
/** @var array                $type_store_ids      id de type => liste de store_id (un type peut couvrir plusieurs stores) */
/** @var int                  $my_user_id          admin courant */
/** @var string               $today */
/** @var string               $prev_start */
/** @var string               $next_start */
/** @var array                $rates_map           uid → type_id → rate */
/** @var array                $currency_map        uid → currency */
/** @var string               $currency_symbol_style  'kanji'|'international' */
/** @var int                  $filter_store_id */
/** @var array                $stores_map          id → nom */
/** @var array                $available_stores    [{id, name, ...}] */
/** @var array                $store_settings      {min_staff_per_day, min_shift_minutes, max_shift_minutes} */
/** @var bool|null            $can_manage          true = affiche import/création/taux horaires/drag-drop (Owner ou RBAC shifts.update sur le store affiché) */
/** @var string|null          $page_heading        titre de page (défaut : "Planning") */
/** @var bool                 $show_request_swap   afficher le bouton "demander un échange" (contexte employé) */

// Défaut à false (pas à true) : cette vue est partagée entre AdminShiftController et
// EmployeeController, tous deux passent désormais explicitement can_manage — un futur appelant
// qui oublierait de le faire ne doit pas exposer accidentellement les taux horaires ni les
// actions d'écriture (import/création/glisser-déposer) à un simple employé.
$_canManage     = $can_manage ?? false;
$_typeStoreIds  = $type_store_ids ?? [];
$_ratesMap      = $rates_map     ?? [];
$_currencyMap   = $currency_map  ?? [];
$_currencySymbolStyle = $currency_symbol_style ?? 'kanji';
$_minStaffDay   = (int) ($store_settings['min_staff_per_day']    ?? 0);
$_minShiftMin   = (int) ($store_settings['min_shift_minutes']    ?? 0);
$_maxShiftMin   = (int) ($store_settings['max_shift_minutes']    ?? 0);
$_lowStaffStart = (int) ($store_settings['low_staff_hour_start'] ?? -1);
$_lowStaffEnd   = (int) ($store_settings['low_staff_hour_end']   ?? -1);

// ── Helpers timeline ─────────────────────────────────────────────────────────
$T_START = 6 * 60;   // 06:00
$T_TOTAL = 24 * 60;  // 1440 min

// En-têtes heures
$headerHours = [];
for ($h = 6; $h < 30; $h++) $headerHours[] = $h % 24;

// Couleurs employés
$_palette = ['#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6','#8b5cf6','#f97316','#06b6d4','#ec4899','#84cc16'];
$userColorMap = [];
foreach ($all_user_ids as $i => $uid) {
    $userColorMap[$uid] = $user_color_map[$uid] ?? $_palette[$i % count($_palette)];
}

// Mapping user → store (tous dans le même store dans cette vue)
$_userStoreMap = [];
foreach ($all_user_ids as $uid) {
    $_userStoreMap[$uid] = $filter_store_id;
}

$frDaysFull = [
    'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi',
    'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi', 'Sunday' => 'Dimanche'
];

$firstDay   = $days[0];
$lastDay    = $days[count($days) - 1];
$periodLabel = $period_mode === 'week'
    ? __('period_week_label', ['start' => $firstDay->format('d M'), 'end' => $lastDay->format('d M Y')])
    : __('period_3days_label', ['start' => $firstDay->format('d M'), 'end' => $lastDay->format('d M Y')]);

?>

<?= Flash::fromQuery('success', [
    'created' => __('shift_created'), 'updated' => __('shift_updated'), 'deleted' => __('shift_deleted'),
])->render() ?>

<div class="page-header">
    <?php if ($_canManage): ?>
        <h2 class="page-header__title"><?= $page_heading ?? __('planning') ?> <span class="tl-subtitle">— <?= __('timeline') ?></span></h2>
    <?php else: ?>
        <h2 class="page-header__title"><?= $page_heading ?? __('my_planning') ?></h2>
    <?php endif; ?>
</div>

<div class="shifts-toolbar">
    <div class="btn-group btn-group--switcher btn-group--switcher-3">
        <span class="btn-group__thumb btn-group__thumb--pos-2" aria-hidden="true"></span>
        <a href="<?= route_url($_canManage ? 'admin.shifts' : 'employee.shifts.week') . ($_canManage && $filter_store_id ? '?store_id=' . $filter_store_id : '') ?>" class="btn btn--ghost btn--sm" aria-label="<?= htmlspecialchars(__('list_view')) ?>">☰ <span class="switcher-label"><?= __('list_view') ?></span></a>
        <a href="<?= route_url($_canManage ? 'admin.shifts.calendar' : 'employee.shifts.calendar') . ($_canManage && $filter_store_id ? '?store_id=' . $filter_store_id : '') ?>" class="btn btn--ghost btn--sm" aria-label="<?= htmlspecialchars(__('calendar_view')) ?>">📅 <span class="switcher-label"><?= __('calendar_view') ?></span></a>
        <a href="<?= route_url($_canManage ? 'admin.shifts.timeline' : 'employee.shifts.day') . ($_canManage && $filter_store_id ? '?store_id=' . $filter_store_id : '') ?>" class="btn btn--ghost btn--sm btn--active" aria-label="<?= htmlspecialchars(__('timeline_view')) ?>"><svg class="gantt-icon icon-inline" width="16" height="16" viewBox="0 0 24 24"><rect x="4" y="2" width="2" height="20" fill="currentColor"/><rect x="10" y="6" width="2" height="16" fill="currentColor"/><rect x="16" y="10" width="2" height="12" fill="currentColor"/></svg> <span class="switcher-label"><?= __('timeline_view') ?></span></a>
    </div>
    <div class="btn-group">
        <?php if ($_canManage): ?>
            <?= Button::make('⚡ ' . __('conflict_view'))->ghost()->sm()->link(route_url('admin.shifts.conflicts') . ($filter_store_id ? '?store_id=' . $filter_store_id : ''))->render() ?>
            <?= Button::make('↑ ' . __('import_excel'))->ghost()->sm()->link(route_url('admin.shifts.import'))->render() ?>
            <?= Button::make('+ ' . __('new_shift'))->primary()->sm()->attrs(['data-on-click' => 'sdQcOpen'])->render() ?>
        <?php elseif ($show_request_swap ?? false): ?>
            <?php // Liens des bundles (demande d'échange… — Bundle::actions(), contrat 1.6). ?>
            <?php foreach (\kintai\Core\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::SHIFTS_EMPLOYEE, [], $user_can ?? fn(string $k): bool => true) as $_a): ?>
            <?= Button::make(($_a['icon'] !== '' ? $_a['icon'] . ' ' : '') . $_a['label'])->{$_a['style'] === 'danger' ? 'danger' : ($_a['style'] === 'primary' ? 'primary' : 'ghost')}()->sm()->link($_a['href'])->render() ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($_canManage):
$printHref = route_url('admin.shifts.timeline.print')
    . '?store_id=' . $filter_store_id
    . '&start=' . $days[0]->format('Y-m-d')
    . '&view=' . $period_mode;
?>
<a href="<?= htmlspecialchars($printHref) ?>" target="_blank" rel="noopener" class="fab" title="<?= htmlspecialchars(__('print_timeline')) ?>">🖨 <?= __('print_timeline') ?></a>
<?php endif; ?>

<?php
ob_start();
?>
<div class="filter-bar">
    <?php if (count($available_stores) > 1): ?>
    <form method="GET" action="" class="form-contents">
        <input type="hidden" name="view"  value="<?= htmlspecialchars($period_mode) ?>">
        <input type="hidden" name="start" value="<?= htmlspecialchars($days[0]->format('Y-m-d')) ?>">
        <select name="store_id" class="form-control filter-bar__select" data-submit-on-change>
            <?php foreach ($available_stores as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $filter_store_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php else: ?>
        <span class="filter-bar__store-name"><?= htmlspecialchars($stores_map[$filter_store_id] ?? '') ?></span>
    <?php endif; ?>

    <div class="filter-bar__nav">
        <?= Button::make('←')->ghost()->sm()->link('?store_id=' . $filter_store_id . '&start=' . $prev_start . '&view=' . $period_mode)->render() ?>
        <strong class="filter-bar__period-label"><?= htmlspecialchars($periodLabel) ?></strong>
        <?= Button::make('→')->ghost()->sm()->link('?store_id=' . $filter_store_id . '&start=' . $next_start . '&view=' . $period_mode)->render() ?>
    </div>

    <?php
    // Le mode "3 jours" repart toujours du jour actuel (pas du 1er jour
    // affiché) : basculer sur ce mode depuis une semaine passée/future ne
    // doit pas y rester ancré. Le mode "semaine" garde le contexte de
    // navigation courant, le jour de début de semaine étant déjà recalculé
    // depuis les paramètres du store (voir $weekStartDay côté contrôleur).
    $mode3Href = '?store_id=' . $filter_store_id . '&start=' . $today . '&view=3days';
    $modeWHref = '?store_id=' . $filter_store_id . '&start=' . $days[0]->format('Y-m-d') . '&view=week';
    ?>
    <div class="btn-group btn-group--switcher btn-group--switcher-2">
        <span class="btn-group__thumb btn-group__thumb--pos-<?= $period_mode === 'week' ? 1 : 0 ?>" aria-hidden="true"></span>
        <a href="<?= htmlspecialchars($mode3Href) ?>" class="btn btn--ghost btn--sm<?= $period_mode === '3days' ? ' btn--active' : '' ?>"><?= __('3days') ?></a>
        <a href="<?= htmlspecialchars($modeWHref) ?>" class="btn btn--ghost btn--sm<?= $period_mode === 'week' ? ' btn--active' : '' ?>"><?= __('week') ?></a>
    </div>

    <?= Button::make(__('today'))->ghost()->sm()->link('?store_id=' . $filter_store_id . '&start=' . $today . '&view=' . $period_mode)->render() ?>
</div>
<?= Card::make()->body(ob_get_clean())->render() ?>

<?php if (empty($all_user_ids)): ?>
    <?= Card::make()->body('<div class="empty-state">' . __('no_store_found') . '</div>')->render() ?>
<?php else: ?>

<!-- Jours ────────────────────────────────────────────────────────────────────── -->
<?php foreach ($days as $day): ?>
    <?php
    $dateStr  = $day->format('Y-m-d');
    $isToday  = $dateStr === $today;
    $dayName  = __(strtolower($day->format('l')));

    $dayShifts = [];
    foreach ($all_user_ids as $uid) {
        foreach ($shifts_by_date_user[$dateStr][$uid] ?? [] as $s) {
            $tid = (int) ($s['shift_type_id'] ?? 0);
            $dayShifts[] = array_merge($s, [
                '_uid'       => $uid,
                '_name'      => $users_map[$uid] ?? ('#' . $uid),
                '_is_me'     => $uid === $my_user_id,
                '_color'     => $userColorMap[$uid] ?? '#6366f1',
                '_type_name' => $types_map[$tid]['name'] ?? 'Shift',
            ]);
        }
    }

    usort($dayShifts, function ($a, $b) use ($T_START) {
        $sm = fn($s) => (($v = TimelineHelpers::atMin($s['start_time'] ?? '00:00')) < $T_START) ? $v + 1440 : $v;
        return $sm($a) - $sm($b);
    });

    $lanes      = TimelineHelpers::atLanes($dayShifts, $T_START);
    $shiftCount = count($dayShifts);
    // ── Diagnostics du jour ────────────────────────────────────────────────
    $_diagShifts = [];
    foreach ($dayShifts as $_sh) {
        $_sm = TimelineHelpers::atMin($_sh['start_time'] ?? '00:00');
        $_em = TimelineHelpers::atMin($_sh['end_time']   ?? '00:00');
        if (!empty($_sh['cross_midnight']) || $_em <= $_sm) $_em += 1440;
        if ($_sm < $T_START) { $_sm += 1440; $_em += 1440; }
        $_diagShifts[] = array_merge($_sh, ['_sm' => $_sm, '_em' => $_em]);
    }

    $_staffCount  = count(array_unique(array_column($_diagShifts, '_uid')));

    // Staffing par heure (tranches 0h–23h)
    $_hourlyStaff = array_fill(0, 24, 0);
    foreach ($_diagShifts as $_sh) {
        for ($_h = 0; $_h < 24; $_h++) {
            if ($_sh['_sm'] < ($_h + 1) * 60 && $_sh['_em'] > $_h * 60) {
                $_hourlyStaff[$_h]++;
            }
        }
    }
    $_peakConcurrent = max(array_merge([0], $_hourlyStaff));

    $_conflicts   = [];
    $_shortShifts = [];
    $_longShifts  = [];

    $_byUser = [];
    foreach ($_diagShifts as $_sh) $_byUser[$_sh['_uid']][] = $_sh;
    foreach ($_byUser as $_uid => $_ushifts) {
        usort($_ushifts, fn($a, $b) => $a['_sm'] - $b['_sm']);
        for ($_i = 1; $_i < count($_ushifts); $_i++) {
            if ($_ushifts[$_i]['_sm'] < $_ushifts[$_i - 1]['_em']) {
                $_conflicts[] = ($users_map[$_uid] ?? ('#' . $_uid))
                    . ' : ' . TimelineHelpers::atFmt($_ushifts[$_i - 1]['_sm']) . '–' . TimelineHelpers::atFmt($_ushifts[$_i - 1]['_em'])
                    . ' / ' . TimelineHelpers::atFmt($_ushifts[$_i]['_sm']) . '–' . TimelineHelpers::atFmt($_ushifts[$_i]['_em']);
            }
        }
    }

    foreach ($_diagShifts as $_sh) {
        $_dur = $_sh['_em'] - $_sh['_sm'];
        if ($_minShiftMin > 0 && $_dur < $_minShiftMin) {
            $_shortShifts[] = $_sh['_name'] . ' ' . TimelineHelpers::atFmt($_sh['_sm']) . '–' . TimelineHelpers::atFmt($_sh['_em'])
                . ' (' . intdiv($_dur, 60) . 'h' . str_pad($_dur % 60, 2, '0', STR_PAD_LEFT) . ')';
        }
        if ($_maxShiftMin > 0 && $_dur > $_maxShiftMin) {
            $_longShifts[] = $_sh['_name'] . ' ' . TimelineHelpers::atFmt($_sh['_sm']) . '–' . TimelineHelpers::atFmt($_sh['_em'])
                . ' (' . intdiv($_dur, 60) . 'h' . str_pad($_dur % 60, 2, '0', STR_PAD_LEFT) . ')';
        }
    }

    // Plages horaires sous-effectif (heure couverte par ≥1 employé mais < minimum requis,
    // hors plage à effectif réduit configurée)
    $_lowExempt = function (int $h) use ($_lowStaffStart, $_lowStaffEnd): bool {
        if ($_lowStaffStart < 0 || $_lowStaffEnd < 0) return false;
        if ($_lowStaffStart < $_lowStaffEnd) return $h >= $_lowStaffStart && $h < $_lowStaffEnd;
        return $h >= $_lowStaffStart || $h < $_lowStaffEnd; // plage traversant minuit
    };
    $_understaffedRanges = [];
    if ($_minStaffDay > 0) {
        $_inRange = false; $_rStart = 0; $_rMin = 0;
        for ($_h = 0; $_h < 24; $_h++) {
            $_cnt = $_hourlyStaff[$_h];
            if ($_cnt > 0 && $_cnt < $_minStaffDay && !$_lowExempt($_h)) {
                if (!$_inRange) { $_inRange = true; $_rStart = $_h; $_rMin = $_cnt; }
                else { $_rMin = min($_rMin, $_cnt); }
            } else {
                if ($_inRange) {
                    $_understaffedRanges[] = sprintf('%02d:00–%02d:00 (%d/%d)', $_rStart, $_h, $_rMin, $_minStaffDay);
                    $_inRange = false;
                }
            }
        }
        if ($_inRange) {
            $_understaffedRanges[] = sprintf('%02d:00–24:00 (%d/%d)', $_rStart, $_rMin, $_minStaffDay);
        }
    }
    $_understaffed = !empty($_understaffedRanges);
    $_hasIssues    = !empty($_conflicts) || !empty($_shortShifts) || !empty($_longShifts) || $_understaffed;
    $_issueCount   = count($_conflicts) + count($_shortShifts) + count($_longShifts) + ($_understaffed ? 1 : 0);
    $_diagJson     = htmlspecialchars(json_encode([
        'staff'               => $_staffCount,
        'peak'                => $_peakConcurrent,
        'min_staff'           => $_minStaffDay,
        'understaffed'        => $_understaffed,
        'understaffed_ranges' => $_understaffedRanges,
        'conflicts'           => $_conflicts,
        'short'               => $_shortShifts,
        'long'                => $_longShifts,
    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    ?>
    <div class="card sd-day-card tl-day-card <?= $isToday ? 'tl-day-card--today' : '' ?>" data-date="<?= htmlspecialchars($dateStr) ?>">
        <div class="tl-scroll">
            <div class="tl-scroll-inner">

                <!-- En-tête du jour -->
                <div class="tl-day-header">
                    <div class="tl-day-col">
                        <span class="tl-day-name--<?= $isToday ? 'today' : 'normal' ?>"><?= $dayName ?></span>
                        <span class="tl-day-sub"><?= $day->format('d M') ?></span>
                        <?php if ($isToday): ?><?= Badge::make(__('today'))->active()->sm()->render() ?><?php endif; ?>
                    </div>
                    <span class="tl-day-stats">
                        <?= $shiftCount ?> shift<?= $shiftCount !== 1 ? 's' : '' ?>
                        <?php if ($_canManage && !empty($dayShifts)): ?>
                            · <?= $_staffCount ?> <?= __('staff_abbr') ?>
                            <?php if ($_minStaffDay > 0): ?>
                                · <?= __('peak_abbr') ?> <?= $_peakConcurrent ?>/<?= $_minStaffDay ?> <?= __('simult_abbr') ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($_canManage && $_hasIssues): ?>
                        <span class="tl-diag-badge sd-diag-badge"
                              data-diag="<?= $_diagJson ?>">
                            ⚠ <?= $_issueCount ?>
                        </span>
                    <?php elseif ($_canManage && !empty($dayShifts)): ?>
                        <span class="tl-diag-ok" title="<?= __('no_issue_detected') ?>">✓</span>
                    <?php endif; ?>
                    <!-- <a href="<?= $BASE_URL ?>/admin/shifts?store_id=<?= $filter_store_id ?>" class="tl-day-link" title="<?= __('list_view') ?>"><?= __('list_link') ?></a> -->
                </div>

                <!-- Gantt -->
                <div class="tl-gantt-body">

                    <!-- Axe des heures -->
                    <div class="tl-hours-axis">
                        <?php foreach ($headerHours as $i => $h): ?>
                            <div class="tl-hours-cell">
                                <?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($lanes)): ?>
                        <div class="tl-empty-lane">
                            <span class="tl-empty-text">—</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lanes as $li => $laneShifts): ?>
                            <div class="sd-lane tl-lane <?= $li > 0 ? 'tl-lane--mt' : '' ?>">
                                <!-- Grille verticale -->
                                <?php for ($i = 0; $i <= 24; $i++): ?>
                                    <div class="tl-grid-line <?= style_class(['left' => number_format($i/24*100, 5) . '%', 'opacity' => $i % 6 === 0 ? '.5' : '.2']) ?>"></div>
                                <?php endfor; ?>

                                <!-- Barres shifts -->
                                <?php foreach ($laneShifts as $sh): ?>
                                    <?php
                                    $left  = ($sh['_sm'] - $T_START) / $T_TOTAL * 100;
                                    $width = ($sh['_em'] - $sh['_sm']) / $T_TOTAL * 100;
                                    $left  = max(0, min(100, $left));
                                    $width = max(0.4, min(100 - $left, $width));

                                    $color     = $sh['_color'];
                                    $isMe      = $sh['_is_me'];
                                    $bg        = $isMe ? $color : $color . '70';
                                    $border    = $isMe ? "2px solid {$color}" : "1px solid {$color}aa";
                                    $textColor = wcag_contrast_color($color);
                                    $shadow    = $textColor === '#ffffff' ? '0 1px 2px rgba(0,0,0,.35)' : 'none';

                                    $shUid      = $sh['_uid'];
                                    $shPause    = (int) ($sh['pause_minutes'] ?? 0);
                                    $shStoreId  = (int) ($sh['store_id'] ?? 0);
                                    $shCurrency = $_currencyMap[$shUid] ?? 'JPY';
                                    $shPay = TimelineHelpers::atPayBreakdown(
                                        $sh['start_time'] ?? '00:00', $sh['end_time'] ?? '00:00',
                                        $shPause, !empty($sh['cross_midnight']),
                                        $shUid, $shStoreId, $types_map, $_typeStoreIds, $_ratesMap, $shCurrency, $_currencySymbolStyle
                                    );
                                    $shNetMin     = $shPay['net_minutes'];
                                    $shHoursLabel = intdiv($shNetMin, 60) . 'h' . str_pad($shNetMin % 60, 2, '0', STR_PAD_LEFT)
                                        . ($shPause > 0 ? ' (pause ' . $shPause . ' min)' : '');
                                    // Le taux horaire/montant ne doit atteindre le HTML que si $_canManage : sinon
                                    // il resterait lisible via le title="" (tooltip navigateur) ou les attributs
                                    // data-pay/data-rate-detail même quand shift-detail-modal.js masque la modale
                                    // (CAN_MANAGE côté JS ne fait que cacher l'affichage, pas retirer la donnée du DOM).
                                    $shPayLabel   = ($_canManage && $shPay['has_rate']) ? format_currency($shPay['total'], $shCurrency, $_currencySymbolStyle) : '';
                                    $shRateDetail = htmlspecialchars(json_encode($_canManage ? $shPay['items'] : [], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                                    $tooltip = htmlspecialchars(
                                        $sh['_name'] . ' · ' . TimelineHelpers::atFmt($sh['_sm']) . '–' . TimelineHelpers::atFmt($sh['_em'])
                                        . ' (' . $sh['_type_name'] . ')'
                                        . "\n" . $shHoursLabel
                                        . ($shPayLabel ? "\n" . $shPayLabel : '')
                                    );
                                    ?>
                                    <div title="<?= $tooltip ?>"
                                         data-on-click="sdModalOpen" data-args='["@this"]'
                                         data-id="<?= (int) ($sh['id'] ?? 0) ?>"
                                         data-date="<?= htmlspecialchars($sh['shift_date'] ?? '') ?>"
                                         data-start="<?= TimelineHelpers::atFmt($sh['_sm']) ?>"
                                         data-end="<?= TimelineHelpers::atFmt($sh['_em']) ?>"
                                         data-name="<?= htmlspecialchars($sh['_name']) ?>"
                                         data-type="<?= htmlspecialchars($sh['_type_name']) ?>"
                                         data-notes="<?= htmlspecialchars($sh['notes'] ?? '') ?>"
                                         data-color="<?= htmlspecialchars($color) ?>"
                                         data-hours="<?= htmlspecialchars($shHoursLabel) ?>"
                                         data-pay="<?= htmlspecialchars($shPayLabel) ?>"
                                         data-rate-detail="<?= $shRateDetail ?>"
                                         data-width-pct="<?= number_format($width, 5) ?>"
                                         <?php if ($_canManage): ?>data-draggable="1"<?php endif; ?>
                                         class="tl-bar<?= (int) ($highlight_id ?? 0) > 0 && (int) ($sh['id'] ?? 0) === (int) $highlight_id ? ' tl-bar--highlight' : '' ?> <?= style_class(['left' => number_format($left, 5) . '%', 'width' => number_format($width, 5) . '%', 'background' => $bg, 'border' => $border, 'color' => $textColor, 'text-shadow' => $shadow]) ?>">
                                        <?php if ($width > 4): ?>
                                            <span class="tl-bar-label tl-bar-label--main">
                                                <?= TimelineHelpers::atFmt($sh['_sm']) ?>–<?= TimelineHelpers::atFmt($sh['_em']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($width > 9): ?>
                                            <span class="tl-bar-label tl-bar-label--sub">
                                                <?= htmlspecialchars($sh['_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($_canManage): ?>
                    <!-- Zone de création par drag -->
                    <div class="sd-create-zone">
                        <span class="sd-create-zone__hint">
                            <span class="sd-create-zone__plus">+</span> <?= __('zone_to_create') ?>
                        </span>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Légende employés -->
<div class="week-legend tl-legend">
    <?php foreach ($all_user_ids as $uid): ?>
        <?php $c = $userColorMap[$uid] ?? '#6366f1'; $n = $users_map[$uid] ?? ('#' . $uid); $isMe = $uid === $my_user_id; ?>
        <span class="week-legend-item">
            <span class="tl-legend-bar <?= style_class(['background' => $isMe ? $c : $c . '70', 'border' => $isMe ? "2px solid {$c}" : "1px solid {$c}aa"]) ?>"></span>
            <span class="<?= $isMe ? 'tl-legend-name--me' : 'text-muted tl-legend-name' ?>"><?php if ($_canManage): ?><a href="<?= $BASE_URL ?>/admin/users/<?= employee_segment((int) $uid) ?>/edit"><?= htmlspecialchars($n) ?></a><?php else: ?><?= htmlspecialchars($n) ?><?php endif; ?></span>
        </span>
    <?php endforeach; ?>
    <!-- <span class="text-muted ml-auto"><?= __('timeline_legend_hint') ?></span> -->
</div>

<?php endif; ?>

<!-- ── Modal détail shift ──────────────────────────────────────────────────── -->
<div id="sd-overlay" class="sd-overlay" data-on-click="sdModalClose">
    <div id="sd-modal" class="sd-modal" data-stop-propagation>

        <div id="sd-header" class="sd-modal-header">
            <div>
                <div class="sd-modal-title">
                    <span id="sd-dot" class="sd-dot"></span>
                    <strong id="sd-name" class="sd-modal-name"></strong>
                </div>
                <div id="sd-date" class="sd-modal-sub"></div>
            </div>
            <button data-on-click="sdModalClose" class="sd-modal-close">×</button>
        </div>

        <div class="sd-modal-body">
            <div class="sd-modal-field">
                <span class="sd-modal-label"><?= __('schedule') ?></span>
                <strong id="sd-time"></strong>
            </div>
            <div class="sd-modal-field">
                <span class="sd-modal-label"><?= __('duration') ?></span>
                <span id="sd-hours" class="sd-modal-hours"></span>
            </div>
            <div class="sd-modal-field">
                <span class="sd-modal-label"><?= __('type') ?></span>
                <span id="sd-type"></span>
            </div>
            <?php if ($_canManage): ?>
            <div id="sd-rate-block" class="sd-rate-block">
                <div id="sd-rate-rows"></div>
                <div id="sd-pay-row" class="sd-pay-row">
                    <span class="sd-modal-label"><?= __('total_estimated') ?></span>
                    <strong id="sd-pay" class="sd-pay"></strong>
                </div>
                <div id="sd-no-rate" class="sd-no-rate"><?= __('no_rate_configured') ?></div>
            </div>
            <?php endif; ?>
            <div id="sd-notes-row" class="sd-notes-row">
                <span class="sd-modal-label"><?= __('notes') ?></span>
                <span id="sd-notes" class="sd-notes"></span>
            </div>
        </div>

        <div class="sd-modal-footer">
            <?php if ($_canManage): ?>
            <?= Button::make(__('edit'))->primary()->sm()->attrs(['id' => 'sd-edit-link', 'href' => '#'])->render() ?>
            <form id="sd-delete-form" method="POST" action="#" class="form-inline"
                  data-confirm="<?= htmlspecialchars(__('confirm_delete_shift_permanently'), ENT_QUOTES) ?>">
                <?= csrf_field() ?>
                <?= Button::make(__('delete'))->danger()->sm()->attrs(['type' => 'submit'])->render() ?>
            </form>
            <?php endif; ?>
            <?= Button::make(__('close'))->ghost()->sm()->attrs(['data-on-click' => 'sdModalClose'])->render() ?>
        </div>
    </div>
</div>

<?php if ($_canManage): ?>
<!-- ── Modal création shift (unifié) ─────────────────────────────────────────── -->
<div id="qc-overlay" class="modal">
    <div class="modal__backdrop" data-on-click="sdQcClose"></div>
    <div class="modal__dialog modal__dialog--wide" data-stop-propagation>
        <div class="modal__header">
            <h3 class="modal__title"><?= __('quick_new_shift') ?></h3>
            <button type="button" class="modal__close" data-on-click="sdQcClose">×</button>
        </div>
        <div class="modal__body">
            <?php $shift ??= []; $all_stores ??= $available_stores ?? []; ?>
            <form id="qc-form" method="POST" action="<?= route_url('admin.shifts.create') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                <?php include __DIR__ . '/../_partials/_form-shift.php'; ?>
                <div class="form-actions">
                    <?= Button::make(__('create'))->primary()->attrs(['type' => 'submit', 'id' => 'qc-submit'])->render() ?>
                    <?= Button::make(__('cancel'))->ghost()->attrs(['data-on-click' => 'sdQcClose'])->render() ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($_canManage): ?>
<!-- ── Employé non disponible (déplacement par glisser-déposer) ──────────────── -->
<div id="availability-overlay" class="modal">
    <div class="modal__backdrop" data-on-click="availabilityClose"></div>
    <div class="modal__dialog" data-stop-propagation>
        <div class="modal__header">
            <h3 class="modal__title"><?= __('shift_availability_title') ?></h3>
        </div>
        <div class="modal__body">
            <div class="alert alert--danger" role="alert"><p id="availability-message"></p></div>
        </div>
        <div class="modal__footer">
            <?= Button::make(__('cancel'))->primary()->attrs(['data-on-click' => 'availabilityClose'])->render() ?>
            <?= Button::make(__('shift_availability_force'))->ghost()->attrs(['data-on-click' => 'availabilityForce'])->render() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script type="application/json" id="kintai-timeline-data"><?= json_encode([
    'baseUrl'   => rtrim($BASE_URL, '/'),
    'csrfToken' => csrf_token(),
    'canManage' => $_canManage,
    'i18n'      => [
        'error_prefix'        => __('error_prefix'),
        'network_error'       => __('network_error'),
        'creating'            => __('creating'),
        'create'              => __('create'),
        'staff_planned_label' => __('staff_planned_label'),
        'peak_abbr'           => __('peak_abbr'),
        'simult_abbr'         => __('simult_abbr'),
        'understaffed_warn'   => __('understaffed_warn'),
        'alert_conflicts'     => __('alert_conflicts'),
        'alert_short_shifts'  => __('alert_short_shifts'),
        'alert_long_shifts'   => __('alert_long_shifts'),
    ],
    'users' => array_map(fn($uid) => [
        'id'       => $uid,
        'name'     => $users_map[$uid] ?? ('#' . $uid),
        'store_id' => $_userStoreMap[$uid] ?? 0,
    ], $all_user_ids),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/shift-wage-preview.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/shift-detail-modal.js"></script>
<?php if ($_canManage): ?>
<script src="<?= $BASE_URL ?>/assets/js/modules/timeline-diag.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/timeline-drag.js"></script>
<?php endif; ?>
