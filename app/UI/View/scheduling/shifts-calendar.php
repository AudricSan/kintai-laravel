<?php
use kintai\UI\Components\Button;

/**
 * Calendrier mensuel des shifts — partagé admin/manager (is_manager_view = true)
 * et employé (is_manager_view = false, vue centrée sur ses propres shifts).
 *
 * @var int         $year
 * @var int         $month
 * @var string      $month_label
 * @var string      $month_start   Y-m-d
 * @var string      $month_end     Y-m-d
 * @var string      $prev_month    Y-m
 * @var string      $next_month    Y-m
 * @var string      $today         Y-m-d
 * @var array       $shifts_by_date  'Y-m-d' → shift[]
 * @var array       $types_map     id → shift_type
 * @var array       $stores_map    id → name
 * @var array       $users_colour  uid → '#hex'
 * @var bool        $is_manager_view
 * @var array|null  $timeoff_by_date 'Y-m-d' → array{user_id:int,type:string}[] (admin uniquement)
 * @var array|null  $all_members   user[] (admin uniquement)
 * @var array|null  $members_map   uid → user (admin uniquement)
 * @var int[]|null  $filter_uids   uids sélectionnés (admin uniquement)
 * @var int|null    $filter_store_id (admin uniquement)
 * @var int|null    $my_user_id    (employé uniquement)
 * @var array|null  $ical_links    store_id → url (employé uniquement)
 */

// Défaut à false (pas à true) : les deux appelants (AdminShiftController, EmployeeController)
// passent toujours cette valeur explicitement, mais un futur appelant qui l'oublierait ne doit
// pas basculer accidentellement sur la vue manager (filtres multi-utilisateurs, congés de
// l'équipe) — voir CHANGELOG (même principe que shifts-timeline.php can_manage).
$_isManagerView = $is_manager_view ?? false;

// Construire la grille calendrier selon le jour de début de semaine configuré
$firstDay    = new \DateTimeImmutable($month_start);
$lastDay     = new \DateTimeImmutable($month_end);
$wsd         = max(1, min(7, (int) ($week_start_day ?? 1))); // 1=Lun … 7=Dim (ISO)
$startDow    = (int) $firstDay->format('N');
$gridOffset  = ($startDow - $wsd + 7) % 7;
$gridStart   = $firstDay->modify('-' . $gridOffset . ' days');

// En-têtes de colonnes dans l'ordre du jour de début
$allDowKeys     = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
$orderedDowKeys = [];
for ($i = 0; $i < 7; $i++) {
    $orderedDowKeys[] = $allDowKeys[($wsd - 1 + $i) % 7];
}

$weeks  = [];
$cursor = $gridStart;
while ($cursor <= $lastDay || count($weeks) === 0 || (int) $cursor->format('N') !== $wsd) {
    $week = [];
    for ($d = 0; $d < 7; $d++) {
        $week[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    $weeks[] = $week;
    if ($cursor > $lastDay && (int) $cursor->format('N') === $wsd) break;
}

if ($_isManagerView):
    // ── Vue admin/manager ────────────────────────────────────────────────────
    $timeoff_by_date ??= [];
    $typeLabels = [
        'vacation' => __('vacation'), 'sick' => __('sick'), 'personal' => __('personal'),
        'unpaid' => __('unpaid'), 'other' => __('other'),
    ];

    // Paramètres URL pour la navigation (conserver les filtres)
    function calUrl(string $base, string $month, array $filterUids, int $filterStore): string {
        $params = ['month' => $month];
        foreach ($filterUids as $uid) $params['u'][] = $uid;
        if ($filterStore > 0) $params['store_id'] = $filterStore;
        return route_url('admin.shifts.calendar') . '?' . http_build_query($params);
    }
else:
    // ── Vue employé ──────────────────────────────────────────────────────────
    $myMonthMinutes = 0;
    foreach ($shifts_by_date as $dayShifts) {
        foreach ($dayShifts as $s) {
            if ((int) ($s['user_id'] ?? 0) !== $my_user_id) continue;
            [$sh, $sm] = explode(':', substr($s['start_time'] ?? '00:00', 0, 5));
            [$eh, $em] = explode(':', substr($s['end_time'] ?? '00:00', 0, 5));
            $start = (int) $sh * 60 + (int) $sm;
            $end   = (int) $eh * 60 + (int) $em;
            if (!empty($s['cross_midnight']) || $end <= $start) $end += 1440;
            $myMonthMinutes += max(0, $end - $start - (int) ($s['pause_minutes'] ?? 0));
        }
    }
    $myMonthH = intdiv($myMonthMinutes, 60);
    $myMonthM = $myMonthMinutes % 60;
endif;
?>

<div class="page-header">
    <h2 class="page-header__title">📅 <?= $_isManagerView ? __('planning') : __('my_planning') ?></h2>
</div>

<div class="shifts-toolbar">
    <div class="btn-group btn-group--switcher btn-group--switcher-3">
        <span class="btn-group__thumb btn-group__thumb--pos-1" aria-hidden="true"></span>
        <a href="<?= route_url($_isManagerView ? 'admin.shifts' : 'employee.shifts.week') . ($_isManagerView && $filter_store_id ? '?store_id=' . $filter_store_id : '') ?>" class="btn btn--ghost btn--sm" aria-label="<?= htmlspecialchars(__('list_view')) ?>">☰ <span class="switcher-label"><?= __('list_view') ?></span></a>
        <a href="<?= route_url($_isManagerView ? 'admin.shifts.calendar' : 'employee.shifts.calendar') . ($_isManagerView && $filter_store_id ? '?store_id=' . $filter_store_id : '') ?>" class="btn btn--ghost btn--sm btn--active" aria-label="<?= htmlspecialchars(__('calendar_view')) ?>">📅 <span class="switcher-label"><?= __('calendar_view') ?></span></a>
        <a href="<?= route_url($_isManagerView ? 'admin.shifts.timeline' : 'employee.shifts.day') . ($_isManagerView && $filter_store_id ? '?store_id=' . $filter_store_id : '') ?>" class="btn btn--ghost btn--sm" aria-label="<?= htmlspecialchars(__('timeline_view')) ?>"><svg class="gantt-icon icon-inline" width="16" height="16" viewBox="0 0 24 24"><rect x="4" y="2" width="2" height="20" fill="currentColor"/><rect x="10" y="6" width="2" height="16" fill="currentColor"/><rect x="16" y="10" width="2" height="12" fill="currentColor"/></svg> <span class="switcher-label"><?= __('timeline_view') ?></span></a>
    </div>
    <div class="btn-group">
        <?php if ($_isManagerView): ?>
            <?= Button::make('⚡ ' . __('conflict_view'))->ghost()->sm()->link(route_url('admin.shifts.conflicts') . ($filter_store_id ? '?store_id=' . $filter_store_id : ''))->render() ?>
            <?= Button::make('↑ ' . __('import_excel'))->ghost()->sm()->link(route_url('admin.shifts.import'))->render() ?>
            <?= Button::make('+ ' . __('new_shift'))->primary()->sm()->link(route_url('admin.shifts.create'))->render() ?>
        <?php else: ?>
            <?php // Liens des bundles (demande d'échange… — Bundle::actions(), contrat 1.6). ?>
            <?php foreach (\kintai\Core\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::SHIFTS_EMPLOYEE, [], $user_can ?? fn(string $k): bool => true) as $_a): ?>
            <?= Button::make(($_a['icon'] !== '' ? $_a['icon'] . ' ' : '') . $_a['label'])->{$_a['style'] === 'danger' ? 'danger' : ($_a['style'] === 'primary' ? 'primary' : 'ghost')}()->sm()->link($_a['href'])->render() ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($_isManagerView): ?>

<div class="card card--filters card--filters--dropdown mb-sm">
    <form method="GET" action="<?= route_url('admin.shifts.calendar') ?>" class="filter-bar" id="calFilterForm">
        <input type="hidden" name="month" value="<?= htmlspecialchars($month_start ? substr($month_start, 0, 7) : date('Y-m')) ?>">
        <div class="shifts-filters__row">
            <?php if (!empty($stores_map) && count($stores_map) > 1): ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="cf-store"><?= __('store') ?></label>
                <select id="cf-store" name="store_id" class="form-control form-control-sm" data-submit-on-change>
                    <option value="0"><?= __('all_stores') ?></option>
                    <?php foreach ($stores_map as $sid => $sname): ?>
                        <option value="<?= $sid ?>" <?= $filter_store_id === $sid ? 'selected' : '' ?>><?= htmlspecialchars($sname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif ($filter_store_id > 0): ?>
                <input type="hidden" name="store_id" value="<?= $filter_store_id ?>">
            <?php endif; ?>

            <?php if (!empty($all_members)): ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label"><?= __('staff') ?></label>
                <div class="cal-employee-filter" id="calEmployeeFilter">
                    <button type="button" class="btn btn--ghost btn--sm cal-employee-filter__toggle" id="calEmployeeToggle" aria-expanded="false">
                        <?php
                        $selCount = empty($filter_uids) ? count($all_members) : count($filter_uids);
                        echo $selCount === count($all_members) ? htmlspecialchars(__('all')) : $selCount . ' ' . htmlspecialchars(__('selected'));
                        ?>
                        <span aria-hidden="true">▾</span>
                    </button>
                    <div class="cal-employee-filter__panel">
                        <div class="cal-sidebar-actions">
                            <?= Button::make(__('all'))->ghost()->sm()->attrs(['data-on-click' => 'calSelectAll', 'data-args' => '[true]'])->render() ?>
                            <?= Button::make(__('none'))->ghost()->sm()->attrs(['data-on-click' => 'calSelectAll', 'data-args' => '[false]'])->render() ?>
                        </div>
                        <?php foreach ($all_members as $m): ?>
                            <?php
                            $uid   = (int) $m['id'];
                            $col   = $users_colour[$uid] ?? '#6366f1';
                            $label = $m['display_name'] ?? $m['email'] ?? ('#' . $uid);
                            $checked = empty($filter_uids) || in_array($uid, $filter_uids, true);
                            ?>
                            <label class="cal-member-item <?= style_class(['--c' => $col]) ?>" title="<?= htmlspecialchars($label) ?>">
                                <input type="checkbox" name="u[]" value="<?= $uid ?>" <?= $checked ? 'checked' : '' ?> data-submit-form="calFilterForm">
                                <span class="cal-member-dot <?= style_class(['background' => $col]) ?>"></span>
                                <span class="cal-member-name"><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="cal-layout">

    <!-- ── Calendrier ──────────────────────────────────────── -->
    <div class="cal-main">

        <!-- Navigation mois -->
        <div class="cal-nav">
            <div class="cal-nav-btns">
                <?= Button::make('← ' . __('prev_month'))->ghost()->sm()->link(calUrl($BASE_URL, $prev_month, $filter_uids, $filter_store_id))->render() ?>
            </div>
            <span class="cal-nav-title"><?= htmlspecialchars($month_label) ?></span>
            <div class="cal-nav-btns">
                <?= Button::make(__('today'))->ghost()->sm()->link(calUrl($BASE_URL, date('Y-m'), $filter_uids, $filter_store_id))->render() ?>
                <?= Button::make(__('next_month') . ' →')->ghost()->sm()->link(calUrl($BASE_URL, $next_month, $filter_uids, $filter_store_id))->render() ?>
            </div>
        </div>

        <!-- Grille -->
        <table class="cal-grid">
            <thead>
                <tr>
                    <?php foreach ($orderedDowKeys as $dow): ?>
                        <th><?= __($dow) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weeks as $week): ?>
                    <tr>
                        <?php foreach ($week as $day): ?>
                            <?php
                            $dateStr     = $day->format('Y-m-d');
                            $isThisMonth = $day->format('Y-m') === substr($month_start, 0, 7);
                            $isToday     = $dateStr === $today;
                            $dayShifts   = $shifts_by_date[$dateStr] ?? [];
                            usort($dayShifts, fn($a, $b) => strcmp($a['start_time']??'', $b['start_time']??''));
                            $maxVisible  = 3;
                            $extra       = max(0, count($dayShifts) - $maxVisible);
                            $classes     = [];
                            if (!$isThisMonth) $classes[] = 'cal-other-month';
                            if ($isToday)      $classes[] = 'cal-today';
                            ?>
                            <td class="<?= implode(' ', $classes) ?>">
                                <div class="cal-day-num"><?= (int) $day->format('j') ?></div>
                                <?php foreach (array_slice($dayShifts, 0, $maxVisible) as $s): ?>
                                    <?php
                                    $uid   = (int) ($s['user_id'] ?? 0);
                                    $tid   = (int) ($s['shift_type_id'] ?? 0);
                                    $type  = $types_map[$tid] ?? null;
                                    $col   = $users_colour[$uid] ?? ($type['color'] ?? '#6366f1');
                                    $bg    = $col . '22';
                                    $uName = '';
                                    if (isset($members_map[$uid])) {
                                        $mu = $members_map[$uid];
                                        $uName = $mu['display_name'] ?? $mu['email'] ?? '';
                                    }
                                    $label = htmlspecialchars(substr($s['start_time']??'',0,5) . ' ' . ($uName ?: ($type['name'] ?? 'Shift')));
                                    $tipParts = [
                                        substr($s['start_time']??'',0,5) . '–' . substr($s['end_time']??'',0,5),
                                        $type['name'] ?? '',
                                        $uName,
                                        $stores_map[(int)($s['store_id']??0)] ?? '',
                                    ];
                                    $tip = htmlspecialchars(implode(' · ', array_filter($tipParts)));
                                    ?>
                                    <span class="cal-pill <?= style_class(['--pill-color' => $col, '--pill-bg' => $bg]) ?>" title="<?= $tip ?>">
                                        <?= $label ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($extra > 0): ?>
                                    <a href="<?= route_url('admin.shifts') ?>?store_id=<?= $filter_store_id ?>" class="cal-pill-more">+<?= $extra ?> <?= __('more') ?></a>
                                <?php endif; ?>
                                <?php foreach (($timeoff_by_date[$dateStr] ?? []) as $to): ?>
                                    <?php
                                    $toUid = (int) ($to['user_id'] ?? 0);
                                    $toUser = $members_map[$toUid] ?? null;
                                    if (!$toUser) continue;
                                    $toName = $toUser['display_name'] ?? $toUser['email'] ?? '';
                                    $toTip  = htmlspecialchars($toName . ' · ' . ($typeLabels[$to['type']] ?? $to['type']));
                                    ?>
                                    <span class="cal-pill cal-pill--timeoff" title="<?= $toTip ?>">
                                        🏖 <?= htmlspecialchars($toName) ?>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="<?= $BASE_URL ?>/assets/js/modules/shifts-calendar.js"></script>

<?php else: ?>


<!-- Navigation mois + stats -->
<div class="ecal-nav">
    <div class="ecal-nav-side">
        <a href="<?= route_url('employee.shifts.calendar') ?>?month=<?= $prev_month ?>" class="btn btn--ghost btn--sm">← <?= __('prev_week') ?></a>
    </div>
    <div class="ecal-nav-center">
        <span class="ecal-nav-title"><?= htmlspecialchars($month_label) ?></span>
        <div class="ecal-stats">
            <span><?= __('hours') ?> : <strong><?= $myMonthH ?>h<?= str_pad((string)$myMonthM, 2, '0', STR_PAD_LEFT) ?></strong></span>
        </div>
    </div>
    <div class="ecal-nav-side">
        <a href="<?= route_url('employee.shifts.calendar') ?>?month=<?= date('Y-m') ?>" class="btn btn--ghost btn--sm"><?= __('today') ?></a>
        <a href="<?= route_url('employee.shifts.calendar') ?>?month=<?= $next_month ?>" class="btn btn--ghost btn--sm"><?= __('next_week') ?> →</a>
    </div>
</div>

<!-- Grille calendrier -->
<div class="card card--flush">
    <table class="ecal-grid">
        <thead>
            <tr>
                <?php foreach ($orderedDowKeys as $dow): ?>
                    <th><?= __($dow) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($weeks as $week): ?>
                <tr>
                    <?php foreach ($week as $day): ?>
                        <?php
                        $dateStr     = $day->format('Y-m-d');
                        $isThisMonth = $day->format('Y-m') === substr($month_start, 0, 7);
                        $isToday     = $dateStr === $today;

                        $dayShifts = array_values(array_filter(
                            $shifts_by_date[$dateStr] ?? [],
                            fn($s) => (int)($s['user_id'] ?? 0) === $my_user_id
                        ));
                        usort($dayShifts, fn($a, $b) => strcmp($a['start_time'] ?? '', $b['start_time'] ?? ''));

                        $classes = [];
                        if (!$isThisMonth)      $classes[] = 'ecal-other';
                        if ($isToday)           $classes[] = 'ecal-today';
                        if (!empty($dayShifts)) $classes[] = 'ecal-has-my-shift';

                        $maxVisible = 3;
                        $extra = max(0, count($dayShifts) - $maxVisible);
                        ?>
                        <td class="<?= implode(' ', $classes) ?>">
                            <div class="ecal-day-num"><?= (int) $day->format('j') ?></div>
                            <?php foreach (array_slice($dayShifts, 0, $maxVisible) as $s): ?>
                                <?php
                                $tid   = (int) ($s['shift_type_id'] ?? 0);
                                $type  = $types_map[$tid] ?? null;
                                $col   = $users_colour[$my_user_id] ?? ($type['color'] ?? '#6366f1');
                                $bg    = $col . '25';
                                $time  = substr($s['start_time'] ?? '', 0, 5) . '–' . substr($s['end_time'] ?? '', 0, 5);
                                $tName = $type['name'] ?? 'Shift';
                                $store = $stores_map[(int)($s['store_id'] ?? 0)] ?? '';
                                $tip   = htmlspecialchars($time . ' · ' . $tName . ($store ? ' · ' . $store : ''));
                                ?>
                                <span class="ecal-pill <?= style_class(['--pill-color' => $col, '--pill-bg' => $bg]) ?>"
                                    title="<?= $tip ?>">
                                    <?= htmlspecialchars("$time $tName") ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($extra > 0): ?>
                                <span class="ecal-more">+<?= $extra ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
