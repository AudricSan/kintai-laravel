<?php
/** @var array  $stats */
/** @var array  $shifts_today */
/** @var array  $users_map          id → display_name */
/** @var array  $enabled_widgets    key → index (array_flip) */
/** @var array  $all_widgets        ordered list of widget keys */
$users_map        ??= [];
$enabled_widgets        ??= array_flip(\kintai\UI\Controller\Web\HomeController::ADMIN_WIDGETS);
$all_widgets            ??= \kintai\UI\Controller\Web\HomeController::ADMIN_WIDGETS;
// Widgets des bundles (Bundle::dashboardWidgets(), contrat 1.4) : HTML déjà rendu par le contrôleur, affiché en fin
// de page ; $enabled_widgets_all = choix de l'utilisateur, y compris ces widgets (panneau « Personnaliser »).
$bundle_widgets         ??= [];
$bundle_widget_meta     ??= ['labels' => [], 'permissions' => [], 'features' => []];
$enabled_widgets_all    ??= $enabled_widgets;
// Ordre et largeur des widgets choisis par l'utilisateur (DashboardLayout), widgets déplaçables en mode édition.
$widget_layout          ??= ['order' => $all_widgets, 'widths' => []];
$_dashLayout            = $widget_layout;

// Garde : la vue peut être rendue plusieurs fois dans un même processus (tests).
if (!function_exists('admin_widget_on')) {
    function admin_widget_on(string $key, array $enabled): bool
    {
        return array_key_exists($key, $enabled);
    }
}

// Feature-gating : null = toutes features actives (owner/is_admin), array = liste des features autorisées
$feat = static fn(?string $f): bool =>
    feat_bundle($f) && (
        $f === null || ($store_features ?? null) === null || in_array($f, (array) ($store_features ?? []), true)
    );

// Permission-gating des liens de navigation rapide (même pattern que _topbar.php)
$can = $user_can ?? fn(string $k): bool => true;

// Widgets désactivés si la feature associée est indisponible
$_widgetFeatMap = [
] + $bundle_widget_meta['features'];
// Widgets masqués (panneau de personnalisation + affichage) si la permission requise manque
$_widgetPermMap = [
    'store_stats_summary' => 'payroll.view',
    'financial_overview'  => 'payroll.view',
    'hr_absenteeism'      => 'payroll.view',
] + $bundle_widget_meta['permissions'];
// Filtrer all_widgets par features/permissions pour le panneau de personnalisation
$all_widgets = array_values(array_filter(
    $all_widgets,
    fn(string $wk) => $feat($_widgetFeatMap[$wk] ?? null) && (!isset($_widgetPermMap[$wk]) || $can($_widgetPermMap[$wk]))
));
// Retirer des enabled_widgets les widgets dont la feature/permission est indisponible
foreach ($_widgetFeatMap as $_wk => $_wf) {
    if (!$feat($_wf)) {
        unset($enabled_widgets[$_wk], $enabled_widgets_all[$_wk]);
    }
}
foreach ($_widgetPermMap as $_wk => $_wp) {
    if (!$can($_wp)) {
        unset($enabled_widgets[$_wk], $enabled_widgets_all[$_wk]);
    }
}

$widgetLabels = [
    'kpi_counters'         => __('widget_kpi_counters'),
    'dashboard_alerts'     => __('widget_dashboard_alerts'),
    'store_stats_summary'  => __('widget_store_stats_summary'),
    'financial_overview'   => __('widget_financial_overview'),
    'hr_absenteeism'       => __('widget_hr_absenteeism'),
    'shifts_today'         => __('widget_shifts_today'),
] + $bundle_widget_meta['labels'];

// Widgets employé ajoutés par le gérant (HomeController::EMPLOYEE_WIDGET_PREFIX) : les siens, signalés « personnel ».
$employee_widgets ??= ['enabled' => [], 'shifts_today' => [], 'upcoming' => [], 'stores_map' => [], 'bundle' => []];
$_empPrefix = \kintai\UI\Controller\Web\HomeController::EMPLOYEE_WIDGET_PREFIX;
$widgetLabels += [
    $_empPrefix . 'shifts_today'  => __('my_shifts_today'),
    $_empPrefix . 'upcoming'      => __('my_upcoming_shifts'),
    $_empPrefix . 'monthly_stats' => __('monthly_stats'),
];
foreach ($widgetLabels as $_wk => $_wl) {
    if (str_starts_with((string) $_wk, $_empPrefix)) {
        $widgetLabels[$_wk] = __('dashboard_widget_personal', ['label' => $_wl]);
    }
}
unset($_wk, $_wl);

// Données de graphiques accumulées par les différents widgets, émises une seule fois
// (un seul <script id="kintai-stats-data">) pour éviter une collision d'ID si
// store_stats_summary et financial_overview sont actifs en même temps.
$_dashChartData = [];
?>

<!-- En-tête dashboard + bouton Personnaliser -->
<div class="page-header">
    <h2 class="page-header__title"><?= __('dashboard') ?></h2>
    <div class="page-header__actions">
        <?php include __DIR__ . '/../_partials/_dashboard-actions.php'; ?>
        <button type="button" class="btn btn--ghost btn--sm" data-on-click="adminDashCustomize">
            <?= __('customize') ?>
        </button>
    </div>
</div>

<!-- Panneau de personnalisation -->
<div id="admin-dash-customize" class="dash-customize-panel card card--mb hidden">
    <div class="card-header">
        <span><?= __('dashboard_customize') ?></span>
        <button type="button" class="btn btn--ghost btn--sm" data-on-click="adminDashCustomize"><?= __('close') ?></button>
    </div>
    <div class="card-body">
        <p class="text-sm text-muted"><?= __('dashboard_layout_hint') ?></p>
        <div class="dash-layout-tools">
            <?php
            $_dashHidden = [];
            foreach ($all_widgets as $wk) {
                if (!admin_widget_on($wk, $enabled_widgets_all)) {
                    $_dashHidden[$wk] = $widgetLabels[$wk] ?? $wk;
                }
            }
            include __DIR__ . '/../_partials/_dashboard-add-widget.php';
            unset($_dashHidden);
            ?>
            <button type="button" class="btn btn--ghost btn--sm" data-dash-reset><?= __('dashboard_layout_reset') ?></button>
        </div>
        <?php include __DIR__ . '/../_partials/_dashboard-layouts.php'; ?>
    </div>
</div>

<script src="<?= $BASE_URL ?>/assets/js/modules/admin-dashboard.js"></script>

<?php $_dashBlocks = []; ?>
<?php ob_start(); ?>
<?php if (admin_widget_on('kpi_counters', $enabled_widgets)): ?>
<!-- KPI Stats -->
<?php $_pendingVisible = $pending_requests_visible ?? false; // au moins une sorte de demande visible (PendingRequests) ?>
<?php $_statCount = 3 + ($_pendingVisible ? 1 : 0); ?>
<div class="stat-grid <?= style_class(['--stat-cols' => $_statCount]) ?>"><?php unset($_statCount); ?>
    <?php if ($can('employees.view')): ?>
    <a href="<?= route_url('admin.users') ?>" class="stat-card stat-card--link">
    <?php else: ?>
    <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card__icon stat-card__icon--primary">👥</div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $stats['users'] ?></div>
            <div class="stat-card__label"><?= __('users_plural') ?></div>
        </div>
    <?= $can('employees.view') ? '</a>' : '</div>' ?>

    <?php if ($can('stores.view')): ?>
    <a href="<?= route_url('admin.stores') ?>" class="stat-card stat-card--link">
    <?php else: ?>
    <div class="stat-card">
    <?php endif; ?>
        <div class="stat-card__icon stat-card__icon--success">🏬</div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $stats['stores'] ?></div>
            <div class="stat-card__label"><?= __('stores_plural') ?></div>
        </div>
    <?= $can('stores.view') ? '</a>' : '</div>' ?>

    <div class="stat-card stat-card--has-action">
        <?php if ($can('shifts.view')): ?>
        <a href="<?= route_url('admin.shifts') ?>" class="stat-card__stretched-link" aria-label="<?= htmlspecialchars(__('shifts_today')) ?>"></a>
        <?php endif; ?>
        <div class="stat-card__icon stat-card__icon--warning">📅</div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= $stats['shifts_today'] ?></div>
            <div class="stat-card__label"><?= __('shifts_today') ?></div>
        </div>
        <?php if ($can('shifts.view')): ?>
        <a href="<?= route_url('admin.shift_types') ?>" class="stat-card__action-btn" title="<?= htmlspecialchars(__('shift_types')) ?>">🏷️</a>
        <?php endif; ?>
    </div>

    <?php if ($_pendingVisible): ?>
    <a href="<?= route_url('admin.requests') ?>" class="stat-card stat-card--link">
        <div class="stat-card__icon stat-card__icon--danger">⏳</div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= (int) ($stats['pending_requests'] ?? 0) ?></div>
            <div class="stat-card__label"><?= __('pending_requests') ?></div>
        </div>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $_dashBlocks['kpi_counters'] = ob_get_clean(); ?>

<?php
$dashboard_alerts ??= null;
$_alertTotal = $dashboard_alerts !== null
    ? count($dashboard_alerts['unfilled_shifts']) + count($dashboard_alerts['users_without_shift'])
        + count($dashboard_alerts['stale_requests']) + count($dashboard_alerts['stale_timeclocks'])
    : 0;
?>
<?php ob_start(); ?>
<?php if (admin_widget_on('dashboard_alerts', $enabled_widgets) && $dashboard_alerts !== null): ?>
<?php
// Alertes actionnables (DashboardAlertService) : 4 groupes, 5 éléments affichés par groupe au plus.
$_alertLimit = 5;
$_alertTime  = static fn(string $t): string => substr($t, 0, 5);
$_alertGroups = [
    'unfilled_shifts' => [__('dashboard_alerts_unfilled_shifts'), static function (array $a) use ($can, $_alertTime): string {
        $text = htmlspecialchars($a['shift_date'] . ' ' . $_alertTime($a['start_time']) . '–' . $_alertTime($a['end_time']) . ' · ' . $a['store_name']);
        return $can('shifts.view') ? '<a href="' . route_url('admin.shifts.edit', ['id' => $a['id']]) . '">' . $text . '</a>' : $text;
    }],
    'users_without_shift' => [__('dashboard_alerts_users_without_shift'), static function (array $a) use ($can): string {
        $text = htmlspecialchars($a['name'] . ' · ' . $a['store_name']);
        return $can('employees.view') ? '<a href="' . route_url('admin.users.edit', ['id' => $a['id']]) . '">' . $text . '</a>' : $text;
    }],
    'stale_requests' => [__('dashboard_alerts_stale_requests'), static function (array $a): string {
        // Libellé du fournisseur de la demande (contrat 1.9).
        $type = __($a['label'] ?? $a['type']);
        return '<a href="' . route_url('admin.requests') . '">' . htmlspecialchars($type . ' #' . $a['id'] . ' · ' . ($a['store_name'] ?? '') . ' · ' . substr($a['created_at'], 0, 10)) . '</a>';
    }],
    'stale_timeclocks' => [__('dashboard_alerts_stale_timeclocks'), static function (array $a): string {
        return htmlspecialchars($a['user_name'] . ' · ' . $a['store_name'] . ' · ' . substr($a['clock_in_time'], 0, 16));
    }],
];
?>
<!-- Alertes -->
<div class="card card--mt">
    <div class="card-header">
        <span><?= __('widget_dashboard_alerts') ?> <span class="page-count">(<?= $_alertTotal ?>)</span></span>
    </div>
    <?php if ($_alertTotal === 0): ?>
        <div class="empty-state"><?= __('dashboard_alerts_none') ?></div>
    <?php else: ?>
        <div class="card-body dash-alerts">
            <?php foreach ($_alertGroups as $_ak => [$_alabel, $_aformat]): ?>
                <?php $_aitems = $dashboard_alerts[$_ak]; if ($_aitems === []) continue; ?>
                <div class="dash-alerts__group">
                    <div class="sstat-sublabel--strong"><?= htmlspecialchars($_alabel) ?> <span class="page-count">(<?= count($_aitems) ?>)</span></div>
                    <ul class="dash-alerts__list">
                        <?php foreach (array_slice($_aitems, 0, $_alertLimit) as $_aitem): ?>
                            <li class="dash-alerts__item"><?= $_aformat($_aitem) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($_aitems) > $_alertLimit): ?>
                        <div class="dash-alerts__more"><?= __('dashboard_alerts_more', ['count' => count($_aitems) - $_alertLimit]) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php unset($_alertLimit, $_alertTime, $_alertGroups, $_ak, $_alabel, $_aformat, $_aitems, $_aitem); ?>
<?php endif; ?>
<?php $_dashBlocks['dashboard_alerts'] = ob_get_clean(); ?>

<?php
$store_stats_rows          ??= [];
$store_stats_period        ??= 30;
$store_stats_hours_by_week ??= [];
?>
<?php ob_start(); ?>
<?php if (admin_widget_on('store_stats_summary', $enabled_widgets) && $can('payroll.view') && $store_stats_rows !== []): ?>
<!-- Aperçu statistiques -->
<div class="card card--mt">
    <div class="card-header">
        <span><?= __('widget_store_stats_summary') ?> — <?= (int) $store_stats_period ?>j</span>
    </div>
    <?php if (count($store_stats_rows) === 1): ?>
        <?php $_row = $store_stats_rows[0]; ?>
        <div class="stat-grid stat-grid--cols-3">
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--primary">⏱️</div>
                <div class="stat-card__body">
                    <div class="stat-card__value"><?= number_format((float) $_row['total_hours'], 1) ?><?= __('hours_unit') ?></div>
                    <div class="stat-card__label"><?= __('net_hours') ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--success">💰</div>
                <div class="stat-card__body">
                    <div class="stat-card__value"><?= format_currency((float) $_row['total_cost'], (string) $_row['currency'], (string) $_row['currency_symbol_style']) ?></div>
                    <div class="stat-card__label"><?= __('total_cost') ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--warning">📈</div>
                <div class="stat-card__body">
                    <div class="stat-card__value"><?= $_row['stability'] !== null ? (int) $_row['stability'] : '—' ?></div>
                    <div class="stat-card__label"><?= __('score_stability') ?></div>
                </div>
            </div>
        </div>
        <?php if (count($store_stats_hours_by_week) > 1): ?>
        <div class="card-body">
            <div class="sstat-sublabel--strong"><?= __('weekly_hours_trend') ?></div>
            <div class="sstat-canvas-wrap">
                <canvas id="chart-weekly-hours"></canvas>
            </div>
        </div>
        <?php $_dashChartData['hoursByWeek'] = array_map('floatval', $store_stats_hours_by_week); ?>
        <?php endif; ?>
        <div class="card-body">
            <a href="<?= route_url('admin.stores.stats', ['id' => $_row['store_id']]) ?>" class="card-header-link"><?= __('statistics') ?> →</a>
        </div>
        <?php unset($_row); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table" data-mob-stack>
                <thead>
                    <tr>
                        <th><?= __('store') ?></th>
                        <th><?= __('net_hours') ?></th>
                        <th><?= __('total_cost') ?></th>
                        <th><?= __('score_stability') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($store_stats_rows as $_row): ?>
                        <tr class="tr--clickable" data-goto="<?= route_url('admin.stores.stats', ['id' => $_row['store_id']]) ?>">
                            <td data-label="<?= htmlspecialchars(__('store')) ?>"><?= htmlspecialchars((string) $_row['store_name']) ?></td>
                            <td data-label="<?= htmlspecialchars(__('net_hours')) ?>"><?= number_format((float) $_row['total_hours'], 1) ?><?= __('hours_unit') ?></td>
                            <td data-label="<?= htmlspecialchars(__('total_cost')) ?>"><?= format_currency((float) $_row['total_cost'], (string) $_row['currency'], (string) $_row['currency_symbol_style']) ?></td>
                            <td data-label="<?= htmlspecialchars(__('score_stability')) ?>"><?= $_row['stability'] !== null ? (int) $_row['stability'] : '—' ?></td>
                            <td><a href="<?= route_url('admin.stores.stats', ['id' => $_row['store_id']]) ?>" class="card-header-link"><?= __('statistics') ?> →</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php unset($_row); ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $_dashBlocks['store_stats_summary'] = ob_get_clean(); ?>

<?php
$financial_overview ??= null;
$hr_stats           ??= null;
?>
<?php ob_start(); ?>
<?php if (admin_widget_on('financial_overview', $enabled_widgets) && $can('payroll.view') && $financial_overview !== null): ?>
<div class="card">
    <div class="card-header">
        <span><?= __('widget_financial_overview') ?></span>
    </div>
    <div class="stat-grid stat-grid--in-card stat-grid--cols-2">
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--primary">💰</div>
            <div class="stat-card__body">
                <div class="stat-card__value"><?= format_currency((float) $financial_overview['current_month'], (string) $financial_overview['currency'], (string) $financial_overview['currency_style']) ?></div>
                <div class="stat-card__label">
                    <?= __('current_month') ?>
                    <?php if ($financial_overview['delta_pct'] !== null): ?>
                        <span class="<?= $financial_overview['delta_pct'] > 0 ? 'text-danger' : 'text-success' ?>">
                            (<?= $financial_overview['delta_pct'] > 0 ? '+' : '' ?><?= $financial_overview['delta_pct'] ?>% <?= __('vs_prev_period') ?>)
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon">📆</div>
            <div class="stat-card__body">
                <div class="stat-card__value"><?= format_currency((float) $financial_overview['previous_month'], (string) $financial_overview['currency'], (string) $financial_overview['currency_style']) ?></div>
                <div class="stat-card__label"><?= __('prev_month') ?></div>
            </div>
        </div>
    </div>
    <?php if (count($financial_overview['trend_by_month']) > 1): ?>
    <div class="card-body">
        <div class="sstat-sublabel--strong"><?= __('monthly_trend') ?></div>
        <div class="sstat-canvas-wrap">
            <canvas id="chart-monthly-trend"></canvas>
        </div>
    </div>
    <?php
    $_dashChartData['hasCost']     = true;
    $_dashChartData['costByMonth'] = array_map('floatval', $financial_overview['trend_by_month']);
    ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $_dashBlocks['financial_overview'] = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if (admin_widget_on('hr_absenteeism', $enabled_widgets) && $can('payroll.view') && $hr_stats !== null): ?>
<div class="card">
    <div class="card-header">
        <span><?= __('widget_hr_absenteeism') ?></span>
    </div>
    <div class="stat-grid stat-grid--in-card stat-grid--cols-2">
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--warning">📉</div>
            <div class="stat-card__body">
                <div class="stat-card__value"><?= number_format((float) $hr_stats['abs_rate'], 1) ?>%</div>
                <div class="stat-card__label"><?= __('absence_rate') ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--primary">🌴</div>
            <div class="stat-card__body">
                <div class="stat-card__value"><?= $hr_stats['timeoff_taken'] ?></div>
                <div class="stat-card__label"><?= __('timeoff_taken') ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon stat-card__icon--danger">⏰</div>
            <div class="stat-card__body">
                <div class="stat-card__value"><?= $hr_stats['late_count'] ?></div>
                <div class="stat-card__label"><?= __('late_clock_ins') ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon">⏱️</div>
            <div class="stat-card__body">
                <div class="stat-card__value"><?= $hr_stats['overtime_weeks'] ?></div>
                <div class="stat-card__label"><?= __('overtime_weeks') ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php $_dashBlocks['hr_absenteeism'] = ob_get_clean(); ?>

<?php
$sort       ??= 'start_asc';
$_qs = fn(string $s): string => '?' . http_build_query(['sort' => $s]);
$_icon = function (string $field) use ($sort): string {
    [$f, $d] = explode('_', $sort, 2);
    if ($f !== $field) return '<span class="sort-icon-dim">↕</span>';
    return $d === 'asc' ? '↑' : '↓';
};
$_href = function (string $field) use ($sort, $_qs): string {
    [$f, $d] = explode('_', $sort, 2);
    $dir = ($f === $field && $d === 'asc') ? 'desc' : 'asc';
    return $_qs("{$field}_{$dir}");
};
?>

<?php ob_start(); ?>
<?php if (admin_widget_on('shifts_today', $enabled_widgets) && $can('shifts.view')): ?>
<!-- Shifts du jour -->
<?php
// Regrouper par magasin
$shiftsByStore = [];
foreach ($shifts_today as $shift) {
    $sid   = (int) ($shift['store_id'] ?? 0);
    $sname = (string) ($shift['store_name'] ?? ('Store #' . $sid));
    if (!isset($shiftsByStore[$sid])) {
        $shiftsByStore[$sid] = ['name' => $sname, 'shifts' => []];
    }
    $shiftsByStore[$sid]['shifts'][] = $shift;
}
$hasMultipleStores = count($shiftsByStore) > 1;
?>
<div class="card card--mt">
    <div class="card-header">
        <span><?= __('shifts_of_day') ?> — <?= date('d/m/Y') ?></span>
        <div class="flex-row gap-sm">
            <a href="<?= route_url('admin.shifts') ?>" class="card-header-link"><?= __('view_all') ?></a>
        </div>
    </div>
    <?php if (empty($shifts_today)): ?>
        <div class="empty-state"><?= __('no_shift_today') ?></div>
    <?php else: ?>
        <?php if ($hasMultipleStores): ?>
        <!-- Onglets par magasin -->
        <div class="dash-tabs" id="dash-shifts-tabs">
            <div class="dash-tabs-nav">
                <button type="button" class="dash-tab-btn" data-tab="all" data-on-click="dashTab" data-args='["all"]'>
                    <?= __('all') ?> <span class="badge badge--secondary badge--sm"><?= count($shifts_today) ?></span>
                </button>
                <?php foreach ($shiftsByStore as $sid => $sGroup): ?>
                    <button type="button" class="dash-tab-btn" data-tab="s<?= $sid ?>" data-on-click="dashTab" data-args="<?= htmlspecialchars(json_encode(['s' . $sid]), ENT_QUOTES) ?>">
                        <?= htmlspecialchars($sGroup['name']) ?>
                        <span class="badge badge--secondary badge--sm"><?= count($sGroup['shifts']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Onglet Tous -->
            <div class="dash-tab-panel" id="dash-tab-all">
                <div class="table-wrap">
                    <table class="data-table" data-mob-stack>
                        <thead><tr>
                            <th><a href="<?= $_href('store') ?>" class="sort-link"><?= __('store') ?> <?= $_icon('store') ?></a></th>
                            <th><a href="<?= $_href('user') ?>" class="sort-link"><?= __('user') ?> <?= $_icon('user') ?></a></th>
                            <th><a href="<?= $_href('start') ?>" class="sort-link"><?= __('start') ?> <?= $_icon('start') ?></a></th>
                            <th><a href="<?= $_href('end') ?>" class="sort-link"><?= __('end') ?> <?= $_icon('end') ?></a></th>
                            <th><a href="<?= $_href('pause') ?>" class="sort-link"><?= __('pause') ?> <?= $_icon('pause') ?></a></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($shifts_today as $shift): ?>
                                <tr class="tr--clickable" data-goto="<?= $BASE_URL ?>/admin/shifts/<?= (int)$shift['id'] ?>/edit">
                                    <td data-label="<?= htmlspecialchars(__('store')) ?>"><?= htmlspecialchars((string) ($shift['store_name'] ?? '—')) ?></td>
                                    <td data-label="<?= htmlspecialchars(__('user')) ?>"><?= htmlspecialchars((string) ($shift['user_name'] ?? '—')) ?></td>
                                    <td data-label="<?= htmlspecialchars(__('start')) ?>"><?= htmlspecialchars((string) ($shift['start_time'] ?? '—')) ?></td>
                                    <td data-label="<?= htmlspecialchars(__('end')) ?>"><?= htmlspecialchars((string) ($shift['end_time'] ?? '—')) ?></td>
                                    <td data-label="<?= htmlspecialchars(__('pause')) ?>"><?= (int) ($shift['pause_minutes'] ?? 0) ?> min</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Un onglet par magasin -->
            <?php foreach ($shiftsByStore as $sid => $sGroup): ?>
                <div class="dash-tab-panel" id="dash-tab-s<?= $sid ?>">
                    <div class="table-wrap">
                        <table class="data-table" data-mob-stack>
                            <thead><tr>
                                <th><a href="<?= $_href('user') ?>" class="sort-link"><?= __('user') ?> <?= $_icon('user') ?></a></th>
                                <th><a href="<?= $_href('start') ?>" class="sort-link"><?= __('start') ?> <?= $_icon('start') ?></a></th>
                                <th><a href="<?= $_href('end') ?>" class="sort-link"><?= __('end') ?> <?= $_icon('end') ?></a></th>
                                <th><a href="<?= $_href('pause') ?>" class="sort-link"><?= __('pause') ?> <?= $_icon('pause') ?></a></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($sGroup['shifts'] as $shift): ?>
                                    <tr class="tr--clickable" data-goto="<?= $BASE_URL ?>/admin/shifts/<?= (int)$shift['id'] ?>/edit">
                                        <td data-label="<?= htmlspecialchars(__('user')) ?>"><?= htmlspecialchars((string) ($shift['user_name'] ?? '—')) ?></td>
                                        <td data-label="<?= htmlspecialchars(__('start')) ?>"><?= htmlspecialchars((string) ($shift['start_time'] ?? '—')) ?></td>
                                        <td data-label="<?= htmlspecialchars(__('end')) ?>"><?= htmlspecialchars((string) ($shift['end_time'] ?? '—')) ?></td>
                                        <td data-label="<?= htmlspecialchars(__('pause')) ?>"><?= (int) ($shift['pause_minutes'] ?? 0) ?> min</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <script nonce="<?= csp_nonce() ?>">
        (function () {
            var LS_KEY = 'kintai_dash_shifts_tab';
            function dashTab(id) {
                document.querySelectorAll('#dash-shifts-tabs .dash-tab-panel').forEach(function (p) { p.style.display = 'none'; });
                document.querySelectorAll('#dash-shifts-tabs .dash-tab-btn').forEach(function (b) { b.classList.remove('dash-tab-btn--active'); });
                var panel = document.getElementById('dash-tab-' + id);
                var btn   = document.querySelector('#dash-shifts-tabs [data-tab="' + id + '"]');
                if (panel) panel.style.display = 'block';
                if (btn)   btn.classList.add('dash-tab-btn--active');
                try { localStorage.setItem(LS_KEY, id); } catch(e) {}
            }
            window.dashTab = dashTab;
            var saved = localStorage.getItem(LS_KEY) || 'all';
            // Vérifier que l'onglet sauvegardé existe (le store peut ne plus être là)
            if (!document.getElementById('dash-tab-' + saved)) saved = 'all';
            dashTab(saved);
        }());
        </script>

        <?php else: ?>
        <!-- Un seul magasin : pas d'onglets -->
        <div class="table-wrap">
            <table class="data-table" data-mob-stack>
                <thead>
                    <tr>
                        <th>#</th>
                        <th><a href="<?= $_href('user') ?>" class="sort-link"><?= __('user') ?> <?= $_icon('user') ?></a></th>
                        <th><a href="<?= $_href('start') ?>" class="sort-link"><?= __('start') ?> <?= $_icon('start') ?></a></th>
                        <th><a href="<?= $_href('end') ?>" class="sort-link"><?= __('end') ?> <?= $_icon('end') ?></a></th>
                        <th><a href="<?= $_href('pause') ?>" class="sort-link"><?= __('pause') ?> <?= $_icon('pause') ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shifts_today as $shift): ?>
                        <tr class="tr--clickable" data-goto="<?= $BASE_URL ?>/admin/shifts/<?= (int)$shift['id'] ?>/edit">
                            <td><?= (int) $shift['id'] ?></td>
                            <td data-label="<?= htmlspecialchars(__('user')) ?>"><?= htmlspecialchars((string) ($shift['user_name'] ?? '—')) ?></td>
                            <td data-label="<?= htmlspecialchars(__('start')) ?>"><?= htmlspecialchars((string) ($shift['start_time'] ?? '—')) ?></td>
                            <td data-label="<?= htmlspecialchars(__('end')) ?>"><?= htmlspecialchars((string) ($shift['end_time'] ?? '—')) ?></td>
                            <td data-label="<?= htmlspecialchars(__('pause')) ?>"><?= (int) ($shift['pause_minutes'] ?? 0) ?> min</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $_dashBlocks['shifts_today'] = ob_get_clean(); ?>


<?php
foreach ($bundle_widgets as $_bundleWidget) {
    $_dashBlocks[$_bundleWidget['key']] = $_bundleWidget['html'];
}

// Widgets employé du gérant : ceux du Core (même rendu que sur le tableau de bord employé), puis ceux des bundles.
$_empWidgets     = $employee_widgets['enabled'];
$_empStats       = $employee_month_stats ?? null;
$_empShiftsToday = $employee_widgets['shifts_today'];
$_empUpcoming    = $employee_widgets['upcoming'];
$_empStoresMap   = $employee_widgets['stores_map'];
include __DIR__ . '/../_partials/_employee-widgets.php';
$_empShown = false;
foreach ($_empBlocks as $_ek => $_eh) {
    $_dashBlocks[$_empPrefix . $_ek] = $_eh;
    $_empShown = $_empShown || trim($_eh) !== '';
}
foreach ($employee_widgets['bundle'] as $_bundleWidget) {
    $_dashBlocks[$_bundleWidget['key']] = $_bundleWidget['html'];
}
unset($_empWidgets, $_empStats, $_empShiftsToday, $_empUpcoming, $_empStoresMap, $_empBlocks, $_ek, $_eh);
$_dashLabels  = $widgetLabels;
$_dashSaveUrl = route_url('admin.dashboard.layout');
$_dashWidgetsUrl = route_url('admin.dashboard.widgets');
include __DIR__ . '/../_partials/_dashboard-grid.php';
unset($_bundleWidget, $_dashBlocks, $_dashLabels, $_dashSaveUrl, $_dashWidgetsUrl);
?>
<?php if ($_empShown): // Navigation de mois et détail des shifts de « Mes stats du mois ». ?>
<script src="<?= $BASE_URL ?>/assets/js/modules/dashboard.js"></script>
<?php endif; ?>

<?php if ($_dashChartData !== []): ?>
<script type="application/json" id="kintai-stats-data"><?= json_encode($_dashChartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/stats-charts.js"></script>
<?php endif; ?>
