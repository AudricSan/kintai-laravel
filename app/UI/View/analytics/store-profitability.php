<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Table;

/**
 * @var array  $store
 * @var string $currency         Symbole devise
 * @var string $from             Date début (Y-m-d)
 * @var string $to               Date fin (Y-m-d)
 * @var int    $preset           Preset actif (7|30|90|180|365)
 * @var float  $totalSales
 * @var float  $totalLaborMnl    Coût MO saisi dans daily reports
 * @var float  $totalLaborCalc   Coût MO calculé depuis les shifts
 * @var int    $totalCustomers
 * @var float|null $avgLaborPct  Labor Cost %
 * @var float|null $revPerCust   CA / client
 * @var float  $variance         Écart manuel − calculé
 * @var int    $reportCount      Nombre de rapports avec CA > 0
 * @var array  $rows             Lignes journalières
 * @var array  $salesByMonth
 * @var array  $laborMnlByMonth
 * @var array  $laborCalcByMonth
 * @var array  $lcPctByMonth
 * @var array  $customersByMonth
 * @var array  $salesByDay
 * @var array  $lcPctByDay
 * @var string $BASE_URL
 */

$storeId = (int) $store['id'];

// Garde : la vue peut être rendue deux fois dans un même processus (tests, aperçu puis PDF) ; une seconde
// déclaration de ces fonctions globales serait une erreur fatale (« Cannot redeclare »).
if (!function_exists('profFmt')) {
    function profFmt(float $n, int $dec = 0): string {
        return number_format($n, $dec, ',', ' ');
    }
    function profDate(string $d): string {
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return $dt ? $dt->format('d/m/Y') : $d;
    }
    function lcBadgeClass(?float $pct): string {
        if ($pct === null) return '';
        if ($pct > 35) return 'delta--neg';
        if ($pct > 25) return 'delta--neutral';
        return 'delta--pos';
    }
}
?>

<script type="application/json" id="kintai-profit-data"><?= json_encode([
    'salesByMonth'     => array_map('floatval', $salesByMonth),
    'laborByMonth'     => array_map('floatval', $laborMnlByMonth),
    'laborCalcByMonth' => array_map('floatval', $laborCalcByMonth),
    'lcPctByMonth'     => array_map('floatval', $lcPctByMonth),
    'customersByMonth' => array_map('intval',   $customersByMonth),
    'salesByDay'       => array_map('floatval', $salesByDay),
    'lcPctByDay'       => $lcPctByDay,
    'hasCustomers'     => $totalCustomers > 0,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<div class="page-header">
    <h2 class="page-header__title">
        <?= __('profitability') ?> — <?= htmlspecialchars($store['name'] ?? '') ?>
    </h2>
    <div class="page-header__actions">
        <?= Button::make(__('statistics'))->ghost()->sm()->link($BASE_URL . '/admin/stores/' . store_segment($storeId) . '/stats?period=90')->render() ?>
        <?php if (!empty($daily_reports_url)): ?>
        <?= Button::make(__('daily_reports'))->ghost()->sm()->link($daily_reports_url)->render() ?>
        <?php endif; ?>
        <?= Button::make(__('back'))->ghost()->sm()->link(back_url(route_url('admin.stores')))->render() ?>
    </div>
</div>

<?php
ob_start();
?>
<div class="form-flex">
    <div class="form-group">
        <label class="form-label"><?= __('quick_period') ?></label>
        <div class="btn-group">
            <?php foreach ([7 => __('period_7d'), 30 => __('period_30d'), 90 => __('period_3m'), 180 => __('period_6m'), 365 => __('period_1y')] as $d => $label): ?>
                <a href="?preset=<?= $d ?>" class="btn btn--sm <?= $preset == $d ? 'btn--primary' : 'btn--ghost' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <form method="GET" action="" class="form-contents">
        <label class="form-label"><?= __('custom_range') ?></label>
        <div class="form-filter">
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control form-control-sm" data-submit-on-change>
            <span class="text-muted">→</span>
            <input type="date" name="to"   value="<?= htmlspecialchars($to) ?>"   class="form-control form-control-sm" data-submit-on-change>
        </div>
    </form>
</div>
<?= Card::make()->body(ob_get_clean())->render() ?>

<?php if ($reportCount === 0): ?>
    <?= Card::make()->body('<div class="empty-state">' . __('no_daily_reports_period') . '</div>')->render() ?>
<?php else: ?>

<!-- KPI Cards -->
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('profitability_kpis') ?></div>
    <div class="sstat-grid">

        <div class="sstat-card">
            <div class="sstat-card__value text-primary"><?= profFmt($totalSales) ?> <small><?= htmlspecialchars($currency) ?></small></div>
            <div class="sstat-card__label"><?= __('total_revenue') ?></div>
            <div class="sstat-card__sub"><?= $reportCount ?> <?= __('days_with_report') ?></div>
        </div>

        <?php if ($avgLaborPct !== null): ?>
        <div class="sstat-card">
            <div class="sstat-card__value <?= $avgLaborPct > 35 ? 'text-danger' : ($avgLaborPct > 25 ? 'text-warning' : 'text-success') ?>">
                <?= $avgLaborPct ?>%
            </div>
            <div class="sstat-card__label"><?= __('labor_cost_pct') ?></div>
            <div class="sstat-card__sub"><?= __('lc_pct_hint') ?></div>
        </div>
        <?php endif; ?>

        <div class="sstat-card">
            <div class="sstat-card__value"><?= profFmt($totalLaborMnl) ?> <small><?= htmlspecialchars($currency) ?></small></div>
            <div class="sstat-card__label"><?= __('labor_cost_manual') ?></div>
            <div class="sstat-card__sub"><?= __('from_daily_reports') ?></div>
        </div>

        <?php if ($totalLaborCalc > 0): ?>
        <div class="sstat-card">
            <div class="sstat-card__value"><?= profFmt($totalLaborCalc) ?> <small><?= htmlspecialchars($currency) ?></small></div>
            <div class="sstat-card__label"><?= __('labor_cost_calc') ?></div>
            <div class="sstat-card__sub"><?= __('from_shift_rates') ?></div>
        </div>
        <div class="sstat-card">
            <div class="sstat-card__value <?= abs($variance) < 100 ? 'text-success' : 'text-warning' ?>">
                <?= $variance >= 0 ? '+' : '' ?><?= profFmt($variance) ?> <small><?= htmlspecialchars($currency) ?></small>
            </div>
            <div class="sstat-card__label"><?= __('variance_mnl_calc') ?></div>
            <div class="sstat-card__sub"><?= __('variance_hint') ?></div>
        </div>
        <?php endif; ?>

        <?php if ($totalCustomers > 0): ?>
        <div class="sstat-card">
            <div class="sstat-card__value"><?= number_format($totalCustomers, 0, ',', ' ') ?></div>
            <div class="sstat-card__label"><?= __('total_customers') ?></div>
        </div>
        <?php if ($revPerCust !== null): ?>
        <div class="sstat-card">
            <div class="sstat-card__value"><?= profFmt($revPerCust, 0) ?> <small><?= htmlspecialchars($currency) ?></small></div>
            <div class="sstat-card__label"><?= __('rev_per_customer') ?></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php if (count($salesByMonth) > 1): ?>
<!-- Graphiques mensuels -->
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('profitability_trends') ?></div>
    <div class="sstat-two">
        <div class="card">
            <div class="sstat-sublabel--strong"><?= __('monthly_revenue') ?> <small class="text-muted">(<?= htmlspecialchars($currency) ?>)</small></div>
            <div class="sstat-canvas-wrap"><canvas id="chart-profit-sales"></canvas></div>
        </div>
        <div class="card">
            <div class="sstat-sublabel--strong"><?= __('labor_cost_pct_trend') ?></div>
            <div class="sstat-canvas-wrap"><canvas id="chart-profit-lc-pct"></canvas></div>
        </div>
    </div>
    <div class="sstat-two">
        <div class="card">
            <div class="sstat-sublabel--strong"><?= __('monthly_labor_cost') ?> <small class="text-muted">(<?= htmlspecialchars($currency) ?>)</small></div>
            <div class="sstat-canvas-wrap"><canvas id="chart-profit-labor"></canvas></div>
        </div>
        <?php if ($totalCustomers > 0): ?>
        <div class="card">
            <div class="sstat-sublabel--strong"><?= __('monthly_customers') ?></div>
            <div class="sstat-canvas-wrap"><canvas id="chart-profit-customers"></canvas></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Analyse journalière -->
<?php if (count($rows) > 2): ?>
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('daily_analysis') ?></div>
    <div class="sstat-two">
        <div class="card">
            <div class="sstat-sublabel--strong"><?= __('daily_revenue') ?> <small class="text-muted">(<?= htmlspecialchars($currency) ?>)</small></div>
            <div class="sstat-canvas-wrap"><canvas id="chart-profit-daily-sales"></canvas></div>
        </div>
        <div class="card">
            <div class="sstat-sublabel--strong"><?= __('daily_lc_pct') ?></div>
            <div class="sstat-canvas-wrap"><canvas id="chart-profit-daily-lc"></canvas></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Table détaillée -->
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('daily_detail') ?></div>
    <?php
    $showCalc = $totalLaborCalc > 0;
    $t = Table::make()->data(array_reverse($rows))
        ->column(__('date'), fn($row) => profDate($row['date'] ?? ''))
        ->column(__('revenue_col') . ' (' . htmlspecialchars($currency) . ')', fn($row) => '<span class="text-right">' . (($row['sales'] ?? 0) > 0 ? profFmt($row['sales']) : '<span class="text-muted">—</span>') . '</span>', 'text-right')
        ->column(__('customer_count_col'), fn($row) => '<span class="text-right">' . (($row['customers'] ?? 0) > 0 ? $row['customers'] : '<span class="text-muted">—</span>') . '</span>', 'text-right')
        ->column(__('rev_per_cust_col'), fn($row) => '<span class="text-right">' . (($row['rev_per_cust'] ?? null) !== null ? profFmt($row['rev_per_cust'], 0) : '<span class="text-muted">—</span>') . '</span>', 'text-right')
        ->column(__('labor_manual_col') . ' (' . htmlspecialchars($currency) . ')', fn($row) => '<span class="text-right">' . (($row['labor_mnl'] ?? 0) > 0 ? profFmt($row['labor_mnl']) : '<span class="text-muted">—</span>') . '</span>', 'text-right');
    if ($showCalc) {
        $t->column(__('labor_calc_col') . ' (' . htmlspecialchars($currency) . ')', fn($row) => '<span class="text-right">' . (($row['labor_calc'] ?? 0) > 0 ? profFmt($row['labor_calc']) : '<span class="text-muted">—</span>') . '</span>', 'text-right');
    }
    $t->column(__('lc_pct_col'), function($row) {
        if (($row['lc_pct'] ?? null) === null) return '<span class="text-muted text-right">—</span>';
        return '<span class="text-right"><span class="delta-chip ' . lcBadgeClass($row['lc_pct']) . '">' . $row['lc_pct'] . '%</span></span>';
    }, 'text-right');
    if ($showCalc) {
        $varianceSign = fn($v) => $v >= 0 ? '+' : '';
        $t->column(__('variance_col'), function($row) use ($varianceSign) {
            $v = $row['variance'] ?? 0;
            $cls = abs($v) < 10 ? 'text-muted' : ($v > 0 ? 'text-warning' : 'text-success');
            return '<span class="text-right ' . $cls . '">' . $varianceSign($v) . profFmt($v) . '</span>';
        }, 'text-right');
    }
    $t->column(__('status'), fn($row) => Badge::make(__('dr_status_' . ($row['status'] ?? 'draft')))->variant(match($row['status'] ?? 'draft') {
        'validated' => 'active',
        'submitted' => 'warning',
        default     => 'secondary',
    })->sm()->render())
        ->footer(function($data) use ($totalSales, $totalCustomers, $revPerCust, $totalLaborMnl, $totalLaborCalc, $showCalc, $avgLaborPct, $variance, $currency) {
            $html = '<tr class="tr-total">';
            $html .= '<td>' . __('total_row') . '</td>';
            $html .= '<td class="text-right">' . profFmt($totalSales) . '</td>';
            $html .= '<td class="text-right">' . ($totalCustomers > 0 ? $totalCustomers : '—') . '</td>';
            $html .= '<td class="text-right">' . ($revPerCust !== null ? profFmt($revPerCust, 0) : '—') . '</td>';
            $html .= '<td class="text-right">' . profFmt($totalLaborMnl) . '</td>';
            if ($showCalc) {
                $html .= '<td class="text-right">' . profFmt($totalLaborCalc) . '</td>';
            }
            $html .= '<td class="text-right">';
            if ($avgLaborPct !== null) {
                $html .= '<span class="delta-chip ' . lcBadgeClass($avgLaborPct) . '">' . $avgLaborPct . '%</span>';
            } else {
                $html .= '—';
            }
            $html .= '</td>';
            if ($showCalc) {
                $html .= '<td class="text-right">' . ($variance >= 0 ? '+' : '') . profFmt($variance) . '</td>';
            }
            $html .= '<td></td></tr>';
            return $html;
        });
    echo $t->render();
    ?>
</div>

<?php endif; ?>
<script src="<?= $BASE_URL ?>/assets/js/modules/stats-charts.js"></script>
