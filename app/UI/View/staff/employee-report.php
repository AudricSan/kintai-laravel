<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Table;

/**
 * @var array  $store
 * @var int    $period
 * @var string $since
 * @var string $today
 * @var string $currency
 * @var array  $usersMap
 * @var array  $membersMap
 * @var int[]  $memberIds
 * @var array  $employeeStats   uid → [shifts, gross_hours, net_hours, cost, work_days, abs_days, swaps, max_consec, has_rate]
 * @var array  $storeTypesMap
 * @var int    $totalShifts
 * @var float  $totalNetHours
 * @var float  $totalGrossHours
 * @var float  $totalCost
 * @var int    $totalAbsDays
 * @var int    $activeCount
 * @var bool   $anyHasRate
 */

// Garde : la vue peut être rendue deux fois dans un même processus ; une seconde déclaration de ces fonctions
// globales serait une erreur fatale (« Cannot redeclare »).
if (!function_exists('repStatCard')) {
    function repStatCard(string $label, string $value, string $sub = '', string $color = 'var(--color-primary)'): string {
        return '<div class="sstat-card">'
            . '<div class="sstat-card__value ' . style_class(['color' => $color]) . '">' . htmlspecialchars($value) . '</div>'
            . '<div class="sstat-card__label">' . htmlspecialchars($label) . '</div>'
            . ($sub !== '' ? '<div class="sstat-card__sub">' . htmlspecialchars($sub) . '</div>' : '')
            . '</div>';
    }

    function repBarChart(array $data, string $color = 'var(--color-primary)', int $maxBarHeight = 80): string {
        if (!$data) return '<em class="sstat-empty">' . __('no_data') . '</em>';
        $max = max(array_values($data)) ?: 1;
        $out = '<div class="sstat-bar-chart">';
        foreach ($data as $label => $val) {
            $h = round($val / $max * $maxBarHeight);
            $display = is_float($val) ? round($val, 1) : $val;
            $out .= '<div class="sstat-bar-col" title="' . htmlspecialchars($label) . ': ' . $display . '">'
                . '<div class="sstat-bar-value">' . $display . '</div>'
                . '<div class="sstat-bar ' . style_class(['height' => $h . 'px', 'background' => $color]) . '"></div>'
                . '<div class="sstat-bar-label">' . htmlspecialchars($label) . '</div>'
                . '</div>';
        }
        return $out . '</div>';
    }

    function repUserName(array $usersMap, int $uid): string {
        if (!isset($usersMap[$uid])) return '#' . $uid;
        $u = $usersMap[$uid];
        $name = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? ''));
        return $name ?: ($u['display_name'] ?? ($u['email'] ?? '#' . $uid));
    }

    function repHoursFormat(float $hours): string {
        $h = intdiv((int) round($hours * 60), 60);
        $m = (int) round($hours * 60) % 60;
        return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
}

$currencyStyle = store_currency_style($store);

?>

<div class="page-header">
    <h2 class="page-header__title">
        <?= __('report_employees') ?> — <?= htmlspecialchars($store['name'] ?? '') ?>
        <span class="page-count">
            <?= __('period_from_to', ['from' => htmlspecialchars($since), 'to' => htmlspecialchars($today)]) ?>
        </span>
    </h2>
    <div class="page-header__actions">
        <?php $_actionCan = $user_can ?? fn(string $k): bool => true; // Liens des bundles (rapport de salaire… — Bundle::actions(), contrat 1.6). ?>
        <?php foreach (\kintai\Core\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::STORE_REPORT, ['store_id' => (int) $store['id']], $_actionCan) as $_a): ?>
        <?= Button::make(($_a['icon'] !== '' ? $_a['icon'] . ' ' : '') . $_a['label'])->{$_a['style'] === 'danger' ? 'danger' : ($_a['style'] === 'primary' ? 'primary' : 'ghost')}()->sm()->link($_a['href'])->render() ?>
        <?php endforeach; ?>
        <?= Button::make('⎙ ' . __('export_pdf'))->primary()->sm()->attrs(['data-on-click' => '@print'])->render() ?>
        <?= Button::make(__('statistics'))->ghost()->sm()->link($BASE_URL . '/admin/stores/' . store_segment((int) $store['id']) . '/stats')->render() ?>
        <?= Button::make(__('back'))->ghost()->sm()->link(back_url(route_url('admin.stores')))->render() ?>
    </div>
</div>

<div class="sstat-period-nav">
    <?php foreach ([7 => __('period_7d'), 30 => __('period_30d'), 90 => __('period_3m'), 180 => __('period_6m'), 365 => __('period_1y')] as $d => $label): ?>
        <a href="?period=<?= $d ?>" class="<?= $period == $d ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- Cartes résumé -->
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('section_employee_perf') ?></div>
    <div class="sstat-grid">
        <?= repStatCard(
            __('active_employees'),
            $activeCount . ' / ' . count($memberIds),
            __('on_period'),
            'var(--color-primary)'
        ) ?>
        <?= repStatCard(
            __('shifts_analyzed'),
            (string) $totalShifts,
            __('on_period')
        ) ?>
        <?= repStatCard(
            __('net_hours'),
            repHoursFormat($totalNetHours),
            number_format($totalNetHours, 1) . ' h ' . __('on_period')
        ) ?>
        <?= repStatCard(
            __('gross_hours'),
            repHoursFormat($totalGrossHours),
            __('on_period')
        ) ?>
        <?php if ($anyHasRate): ?>
        <?= repStatCard(
            __('total_cost'),
            format_currency($totalCost, $currency, $currencyStyle),
            __('on_period'),
            'var(--color-success)'
        ) ?>
        <?php if ($activeCount > 0): ?>
        <?= repStatCard(
            __('avg_per_active_emp'),
            format_currency(round($totalCost / $activeCount, 2), $currency, $currencyStyle),
            __('on_period')
        ) ?>
        <?php endif; ?>
        <?php endif; ?>
        <?= repStatCard(
            __('approved_days'),
            (string) $totalAbsDays,
            __('cumulated_days'),
            'var(--color-warning)'
        ) ?>
    </div>
</div>

<?php if (!$anyHasRate): ?>
<?= Alert::make(__('no_rate_cost_hint'))->info()->render() ?>
<?php endif; ?>

<!-- Tableau détaillé par employé -->
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('section_employee_perf') ?></div>

    <?php if (empty($employeeStats)): ?>
        <p class="sstat-empty"><?= __('no_employee_shift') ?></p>
    <?php else:
        $t = Table::make()->data($employeeStats)
            ->rowAttrs(fn($stat, $uid) => ['class' => ($stat['shifts'] ?? 0) > 0 ? '' : 'erep-row--inactive'])
            ->rowUrl(fn($stat, $uid) => $BASE_URL . '/admin/users/' . employee_segment((int) $uid) . '/edit')
            ->column(__('employee_name'), function($stat, $uid) use ($BASE_URL, $usersMap) {
                $uid = (int) $uid;
                $uName = repUserName($usersMap, $uid);
                $html = '<a href="' . $BASE_URL . '/admin/users/' . employee_segment($uid) . '/edit" class="erep-emp-link">'
                    . htmlspecialchars($uName) . '</a>';
                if (($stat['shifts'] ?? 0) === 0) {
                    $html .= Badge::make('—')->muted()->sm()->render();
                }
                return $html;
            })
            ->column(__('role_col'), function($stat, $uid) use ($membersMap) {
                $roleName     = $membersMap[$uid]['role_name'] ?? '—';
                $isManaging   = !empty($membersMap[$uid]['role_is_managing']);
                return Badge::make($roleName)->variant($isManaging ? 'warning' : 'staff')->render();
            })
            ->column(__('shifts_col'), fn($stat) => '<span class="td-right td-mono">' . ($stat['shifts'] ?? 0) . '</span>', 'td-right')
            ->column(__('work_days_col'), fn($stat) => '<span class="td-right td-mono">' . ($stat['work_days'] ?? 0) . '</span>', 'td-right')
            ->column(__('gross_h_col'), fn($stat) => '<span class="td-right td-mono">' . repHoursFormat($stat['gross_hours'] ?? 0) . '</span>', 'td-right')
            ->column(__('net_h_col'), fn($stat) => '<span class="td-right td-mono ' . (($stat['net_hours'] ?? 0) > 0 ? 'erep-val--highlight' : '') . '">' . repHoursFormat($stat['net_hours'] ?? 0) . '</span>', 'td-right');
        if ($anyHasRate) {
            $t->column(__('estimated_cost_col'), function($stat) use ($currency, $currencyStyle) {
                $html = '<span class="td-right td-mono ' . (($stat['cost'] ?? 0) > 0 ? 'erep-val--cost' : 'td-muted') . '">';
                $html .= ($stat['has_rate'] ?? false) ? format_currency($stat['cost'] ?? 0, $currency, $currencyStyle) : '<span title="' . htmlspecialchars(__('no_rate_set')) . '">—</span>';
                return $html . '</span>';
            }, 'td-right');
            $t->column(__('avg_cost_h'), function($stat) use ($currency, $currencyStyle) {
                $avg = (($stat['net_hours'] ?? 0) > 0 && ($stat['has_rate'] ?? false)) ? format_currency(round(($stat['cost'] ?? 0) / $stat['net_hours'], 2), $currency, $currencyStyle) : '—';
                return '<span class="td-right td-muted td-mono">' . $avg . '</span>';
            }, 'td-right');
        }
        $t->column(__('absence_days_col'), fn($stat) => '<span class="td-right td-mono ' . ((($stat['abs_days'] ?? 0) > 3) ? 'erep-val--warn' : '') . '">' . ((($stat['abs_days'] ?? 0) > 0) ? $stat['abs_days'] : '—') . '</span>', 'td-right')
            ->column(__('swaps_col'), fn($stat) => '<span class="td-right td-mono">' . (($stat['swaps'] ?? 0) > 0 ? $stat['swaps'] : '—') . '</span>', 'td-right')
            ->column(__('max_consec_col'), fn($stat) => '<span class="td-right td-mono ' . ((($stat['max_consec'] ?? 0) >= 6) ? 'erep-val--warn' : '') . '">' . (($stat['max_consec'] ?? 0) > 0 ? $stat['max_consec'] : '—') . '</span>', 'td-right')
            ->column(__('actions'), function($stat, $uid) use ($BASE_URL, $store, $period, $_actionCan) {
                $uid = (int) $uid;
                $statsUrl = $BASE_URL . '/admin/stores/' . store_segment((int) $store['id']) . '/employee-report/' . employee_segment($uid) . '/stats?period=' . $period;
                $html = Button::make('📊 ' . __('view_stats'))->ghost()->sm()->link($statsUrl)->render();
                // Liens des bundles (rapport de salaire… — Bundle::actions(), contrat 1.6).
                $context = ['store_id' => (int) $store['id'], 'user_id' => $uid, 'period' => (int) $period];
                foreach (\kintai\Core\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::STORE_REPORT_ROW, $context, $_actionCan) as $_a) {
                    $html .= Button::make(($_a['icon'] !== '' ? $_a['icon'] . ' ' : '') . $_a['label'])->{$_a['style'] === 'danger' ? 'danger' : ($_a['style'] === 'primary' ? 'primary' : 'ghost')}()->sm()->link($_a['href'])->render();
                }
                return $html;
            })
            ->footer(function($data) use ($totalShifts, $totalGrossHours, $totalNetHours, $anyHasRate, $totalCost, $totalAbsDays, $currency, $currencyStyle) {
                $html = '<tr class="erep-total-row"><td colspan="2"><strong>' . __('total_row') . '</strong></td>';
                $html .= '<td class="td-right td-mono"><strong>' . $totalShifts . '</strong></td>';
                $html .= '<td class="td-right">—</td>';
                $html .= '<td class="td-right td-mono"><strong>' . repHoursFormat($totalGrossHours) . '</strong></td>';
                $html .= '<td class="td-right td-mono"><strong>' . repHoursFormat($totalNetHours) . '</strong></td>';
                if ($anyHasRate) {
                    $html .= '<td class="td-right td-mono erep-val--cost"><strong>' . format_currency($totalCost, $currency, $currencyStyle) . '</strong></td>';
                    $html .= '<td class="td-right">—</td>';
                }
                $html .= '<td class="td-right td-mono"><strong>' . ($totalAbsDays > 0 ? $totalAbsDays : '—') . '</strong></td>';
                $html .= '<td class="td-right">—</td><td class="td-right">—</td><td></td></tr>';
                return $html;
            });
        echo $t->render();
    endif; ?>
</div>

<!-- Graphiques -->
<?php
$chartHours = [];
$chartCosts = [];
foreach ($employeeStats as $uid => $stat) {
    if ($stat['shifts'] > 0) {
        $label = repUserName($usersMap, (int) $uid);
        $chartHours[$label] = $stat['net_hours'];
        if ($anyHasRate && $stat['has_rate']) {
            $chartCosts[$label] = $stat['cost'];
        }
    }
}
arsort($chartHours);
arsort($chartCosts);
?>

<?php if ($chartHours): ?>
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('hours_by_employee_chart') ?></div>
    <?= repBarChart($chartHours, 'var(--color-primary)') ?>
</div>
<?php endif; ?>

<?php if ($chartCosts): ?>
<div class="sstat-section">
    <div class="sstat-section-title"><?= __('cost_by_employee_chart') ?></div>
    <?= repBarChart($chartCosts, 'var(--color-success)') ?>
</div>
<?php endif; ?>

<div class="erep-print-footer">
    <?= htmlspecialchars(__('report_employees')) ?> — <?= htmlspecialchars($store['name'] ?? '') ?> — <?= htmlspecialchars($since) ?> / <?= htmlspecialchars($today) ?>
</div>
