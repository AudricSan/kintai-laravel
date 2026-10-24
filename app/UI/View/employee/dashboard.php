<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Table;

/** @var array $user */
/** @var array $shifts_today */
/** @var array $upcoming */
/** @var array $stores_map  id → store name */
/** @var array|null $employee_month_stats */
/** @var array $enabled_widgets   widget_key → true (flipped) */
/** @var array $all_widgets       list of all widget keys */

$stats       = $employee_month_stats ?? null;
$stores_map  = $stores_map ?? [];
$widgets     = $enabled_widgets ?? array_flip($all_widgets ?? []);
// Widgets des bundles (Bundle::dashboardWidgets(), contrat 1.4) : HTML déjà rendu par le contrôleur, affiché en fin
// de page ; $widgetsAll = choix de l'utilisateur, y compris ces widgets (panneau « Personnaliser »).
$bundle_widgets     ??= [];
$bundle_widget_meta ??= ['labels' => [], 'permissions' => [], 'features' => []];
$widgetsAll  = $enabled_widgets_all ?? $widgets;
// Ordre et largeur des widgets choisis par l'utilisateur (DashboardLayout), widgets déplaçables en mode édition.
$_dashLayout = $widget_layout ?? ['order' => $all_widgets ?? [], 'widths' => []];

// Garde : la vue peut être rendue deux fois dans un même processus ; une seconde déclaration de ces fonctions
// globales serait une erreur fatale (« Cannot redeclare »).
if (!function_exists('widget_on')) {
    function widget_on(string $key, array $widgets): bool {
        return isset($widgets[$key]);
    }
}

$feat = static fn(?string $f): bool =>
    feat_bundle($f) && (
        $f === null || ($store_features ?? null) === null || in_array($f, (array) ($store_features ?? []), true)
    );

// Permission-gating des widgets (même pattern que le dashboard admin, voir dashboard/index.php)
$can = $user_can ?? fn(string $k): bool => true;

$_empWidgetFeatMap = [
] + $bundle_widget_meta['features'];
$_empWidgetPermMap = $bundle_widget_meta['permissions'];
$all_widgets = array_values(array_filter(
    $all_widgets ?? [],
    fn(string $wk) => $feat($_empWidgetFeatMap[$wk] ?? null) && (!isset($_empWidgetPermMap[$wk]) || $can($_empWidgetPermMap[$wk]))
));
foreach ($_empWidgetFeatMap as $_wk => $_wf) {
    if (!$feat($_wf)) {
        unset($widgets[$_wk], $widgetsAll[$_wk]);
    }
}
foreach ($_empWidgetPermMap as $_wk => $_wp) {
    if (!$can($_wp)) {
        unset($widgets[$_wk], $widgetsAll[$_wk]);
    }
}

?>

<div class="page-header">
    <h2 class="page-header__title"><?= __('dashboard') ?></h2>
    <div class="page-header__actions">
        <?php include __DIR__ . '/../_partials/_dashboard-actions.php'; ?>
        <?= Button::make(__('customize'))->ghost()->sm()->attrs(['data-on-click' => 'dashCustomizeToggle'])->render() ?>
    </div>
</div>

<?php
ob_start();
?>
<?php
$widgetLabels = [
    'shifts_today'   => __('my_shifts_today'),
    'upcoming'       => __('my_upcoming_shifts'),
    'monthly_stats'  => __('monthly_stats'),
] + $bundle_widget_meta['labels'];
$_dashHidden = [];
foreach ($all_widgets as $wk) {
    if (!widget_on($wk, $widgetsAll)) {
        $_dashHidden[$wk] = $widgetLabels[$wk] ?? $wk;
    }
}
?>
<p class="text-sm text-muted"><?= __('dashboard_layout_hint') ?></p>
<div class="dash-layout-tools">
    <?php include __DIR__ . '/../_partials/_dashboard-add-widget.php'; ?>
    <button type="button" class="btn btn--ghost btn--sm" data-dash-reset><?= __('dashboard_layout_reset') ?></button>
</div>
<?php include __DIR__ . '/../_partials/_dashboard-layouts.php'; ?>
<?php
unset($_dashHidden);
echo Card::make()
    ->header('<span>' . __('dashboard_customize') . '</span> ' . Button::make(__('close'))->ghost()->sm()->attrs(['data-on-click' => 'dashCustomizeToggle'])->render())
    ->body(ob_get_clean())
    ->attrs(['id' => 'dash-customize', 'class' => 'dash-customize-panel hidden'])
    ->render();
?>

<!-- ═══ Stat cards ═══ -->
<?php $bundle_stats ??= []; // Cartes des bundles (congés, échanges… — Bundle::dashboardStats(), contrat 1.19). ?>
<?php $_statCount = 2 + count($bundle_stats); ?>
<div class="stat-grid <?= style_class(['--stat-cols' => $_statCount]) ?>"><?php unset($_statCount); ?>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--primary">📅</div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= count($shifts_today) ?></div>
            <div class="stat-card__label"><?= __('shifts_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--success">📋</div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= count($upcoming) ?></div>
            <div class="stat-card__label"><?= __('shifts_upcoming') ?></div>
        </div>
    </div>
    <?php foreach ($bundle_stats as $_stat): ?>
    <div class="stat-card">
        <div class="stat-card__icon stat-card__icon--<?= htmlspecialchars($_stat['tone']) ?>"><?= htmlspecialchars($_stat['icon']) ?></div>
        <div class="stat-card__body">
            <div class="stat-card__value"><?= htmlspecialchars((string) $_stat['value']) ?></div>
            <div class="stat-card__label"><?= htmlspecialchars($_stat['label']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php
// Widgets employé du Core, partagés avec le tableau de bord gérant (voir _employee-widgets.php).
$_empWidgets     = $widgets;
$_empStats       = $stats;
$_empShiftsToday = $shifts_today;
$_empUpcoming    = $upcoming;
$_empStoresMap   = $stores_map;
include __DIR__ . '/../_partials/_employee-widgets.php';
$_dashBlocks = $_empBlocks;
unset($_empBlocks);
?>

<?php
foreach ($bundle_widgets as $_bundleWidget) {
    $_dashBlocks[$_bundleWidget['key']] = $_bundleWidget['html'];
}
$_dashLabels  = $widgetLabels;
$_dashSaveUrl = route_url('employee.dashboard.layout');
$_dashWidgetsUrl = route_url('employee.dashboard.widgets');
include __DIR__ . '/../_partials/_dashboard-grid.php';
unset($_bundleWidget, $_dashBlocks, $_dashLabels, $_dashSaveUrl, $_dashWidgetsUrl);
?>

<script src="<?= $BASE_URL ?>/assets/js/modules/dashboard.js"></script>
