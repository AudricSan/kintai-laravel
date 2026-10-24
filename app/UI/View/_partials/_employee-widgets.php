<?php
/**
 * Widgets employé du Core (« Mes stats du mois », « Mes shifts du jour », « Mes prochains shifts »), rendus pour le
 * tableau de bord employé et, préfixés « emp_ », pour le tableau de bord gérant (un gérant peut y ajouter ses widgets
 * personnels ; l'inverse n'est pas proposé).
 *
 * @var array<string, mixed> $_empWidgets     Widgets activés (clé non préfixée => peu importe)
 * @var array|null           $_empStats       Statistiques du mois de l'utilisateur (employee_month_stats)
 * @var array                $_empShiftsToday Ses shifts du jour
 * @var array                $_empUpcoming    Ses prochains shifts
 * @var array<int, string>   $_empStoresMap   id => nom de magasin
 *
 * Résultat : $_empBlocks (clé non préfixée => HTML, vide si le widget est désactivé ou sans données).
 */
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Table;

$_empBlocks = [];
?>
<?php ob_start(); ?>
<?php if (isset($_empWidgets['monthly_stats']) && $_empStats !== null): ?>
<?php $cur = $_empStats['currency'] ?? 'JPY'; $curStyle = $_empStats['currency_symbol_style'] ?? 'kanji'; ?>
<?php
$navPrev = Button::make('←')->ghost()->sm()->attrs(['data-on-click' => 'dashMonthNav', 'data-args' => json_encode([$_empStats['prev_month']]), 'class' => 'dash-month-btn'])->render();
$navNext = Button::make('→')->ghost()->sm()->attrs([
    'data-on-click' => 'dashMonthNav', 'data-args' => json_encode([$_empStats['next_month']]),
    'class' => 'dash-month-btn' . ($_empStats['is_current'] ? ' dash-month-btn--disabled' : ''),
    'disabled' => $_empStats['is_current'] ? 'disabled' : null,
])->render();
$navToday = !$_empStats['is_current']
    ? Button::make(__('today'))->ghost()->sm()->attrs(['data-on-click' => 'dashMonthNav', 'data-args' => json_encode([date('Y-m')])])->render()
    : '';
$headerHtml = $navPrev
    . ' <strong class="dash-month-label">' . htmlspecialchars($_empStats['month_label']) . '</strong> '
    . $navNext . ' ' . $navToday;

ob_start();
?>
<div class="dash-stat">
    <div class="dash-stat__value"><?= number_format($_empStats['hours_month'], 1) ?> <?= __('hours_unit') ?></div>
    <div class="dash-stat__label"><?= __('hours_this_month') ?></div>
</div>
<div class="dash-stat">
    <div class="dash-stat__value dash-stat__value--neutral"><?= number_format($_empStats['hours_week'], 1) ?> <?= __('hours_unit') ?></div>
    <div class="dash-stat__label"><?= __('avg_per_week') ?></div>
</div>
<div class="dash-stat dash-stat--wide">
    <?php if ($_empStats['has_rate']): ?>
        <div class="dash-stat__value dash-stat__value--success"><?= format_currency((float) $_empStats['estimated_pay'], $cur, $curStyle) ?></div>
    <?php else: ?>
        <div class="dash-stat__value--muted">—</div>
    <?php endif; ?>
    <div class="dash-stat__label"><?= __('estimated_pay') ?></div>
</div>
<?php if (!empty($_empStats['shift_details'])): ?>
<div class="dash-stat-actions">
    <?= Button::make(__('details') . ' ▼')->ghost()->sm()->attrs(['id' => 'dash-detail-toggle', 'data-on-click' => 'dashDetailToggle'])->render() ?>
</div>
<?php endif; ?>
<?php
$bodyContent = ob_get_clean();
$detailContent = '';
if (!empty($_empStats['shift_details'])) {
    ob_start();
    ?>
    <?php
    $totalNet = 0; $totalPay = 0.0;
    $dt = Table::make()->data($_empStats['shift_details'])
        ->column(__('date'), fn($r) => '<span class="td-nowrap">' . htmlspecialchars($r['date_label'] ?? '') . '</span>')
        ->column(__('schedule'), fn($r) => '<span class="td-nowrap font-mono">' . htmlspecialchars($r['start'] ?? '') . '–' . htmlspecialchars($r['end'] ?? '') . '</span>')
        ->text(__('type'), fn($r) => $r['type_name'] ?? '')
        ->column(__('net'), function($r) {
            $html = '<span class="td-nowrap">' . htmlspecialchars($r['net_hours_fmt'] ?? '') . '</span>';
            if (($r['pause_min'] ?? 0) > 0) {
                $html .= ' <small class="text-muted">(' . __('pause') . ' ' . (int)$r['pause_min'] . ' min)</small>';
            }
            return $html;
        });
    if ($_empStats['has_rate']) {
        $dt->column(__('rate_h'), fn($r) => '<span class="td-muted">' . htmlspecialchars($r['rate_fmt'] ?? '') . '</span>')
           ->column(__('estimated_pay'), function($r) {
               return '<span class="' . ($r['has_rate'] ? 'pay-ok' : 'pay-none') . '">' . htmlspecialchars($r['pay_fmt'] ?? '') . '</span>';
           }, 'td-right');
    }
    $dt->footer(function($data) use ($totalNet, $_empStats, $cur, $curStyle) {
        $netTotal = 0;
        foreach ($data as $r) { $netTotal += (int) ($r['net_min'] ?? 0); }
        $th = intdiv($netTotal, 60); $tm = $netTotal % 60;
        $html = '<tr class="tr-total"><td colspan="3" class="td-muted">' . __('total') . '</td>';
        $html .= '<td class="td-nowrap">' . $th . 'h' . str_pad((string)$tm, 2, '0', STR_PAD_LEFT) . '</td>';
        if ($_empStats['has_rate']) {
            $html .= '<td></td><td class="pay-ok">' . format_currency((float)$_empStats['estimated_pay'], $cur, $curStyle) . '</td>';
        }
        $html .= '</tr>';
        return $html;
    });
    echo $dt->render();
    ?>
<?php
    $detailContent = '<div id="dash-detail" class="dash-detail-wrap">' . ob_get_clean() . '</div>';
}
echo Card::make()
    ->header($headerHtml)
    ->body($bodyContent . $detailContent)
    ->render();
?>
<?php endif; ?>
<?php $_empBlocks['monthly_stats'] = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if (isset($_empWidgets['shifts_today'])): ?>
<?php
ob_start();
if (empty($_empShiftsToday)):
    echo '<div class="empty-state">' . __('no_shift_today') . '</div>';
else:
?>
<div class="table-wrap">
    <?= Table::make()
        ->data($_empShiftsToday)
        ->text(__('store'), fn($s) => $_empStoresMap[(int)($s['store_id'] ?? 0)] ?? ('Store #' . (int)($s['store_id'] ?? 0)))
        ->text(__('start'), fn($s) => (string) ($s['start_time'] ?? '—'))
        ->text(__('end'), fn($s) => (string) ($s['end_time'] ?? '—'))
        ->column(__('pause'), fn($s) => (int) ($s['pause_minutes'] ?? 0) . ' min')
        ->text(__('notes'), fn($s) => (string) ($s['notes'] ?? ''))
        ->render() ?>
</div>
<?php
endif;
echo Card::make()
    ->header('<span>' . __('my_shifts_today') . ' — ' . date('d/m/Y') . '</span>')
    ->body(ob_get_clean())
    ->render();
?>
<?php endif; ?>
<?php $_empBlocks['shifts_today'] = ob_get_clean(); ?>

<?php ob_start(); ?>
<?php if (isset($_empWidgets['upcoming'])): ?>
<?php
ob_start();
if (empty($_empUpcoming)):
    echo '<div class="empty-state">' . __('no_upcoming_shift') . '</div>';
else:
?>
<div class="table-wrap">
    <?= Table::make()
        ->data($_empUpcoming)
        ->text(__('date'), fn($s) => (string) ($s['shift_date'] ?? '—'))
        ->text(__('store'), fn($s) => $_empStoresMap[(int)($s['store_id'] ?? 0)] ?? ('Store #' . (int)($s['store_id'] ?? 0)))
        ->text(__('start'), fn($s) => (string) ($s['start_time'] ?? '—'))
        ->text(__('end'), fn($s) => (string) ($s['end_time'] ?? '—'))
        ->render() ?>
</div>
<?php
endif;
echo Card::make()
    ->header('<span>' . __('my_upcoming_shifts') . '</span> ' . Button::make(__('view_all'))->ghost()->sm()->link(route_url('employee.shifts.day'))->render())
    ->body(ob_get_clean())
    ->render();
?>
<?php endif; ?>
<?php $_empBlocks['upcoming'] = ob_get_clean(); ?>
<?php unset($bodyContent, $cur, $curStyle, $detailContent, $dt, $headerHtml, $html, $navNext, $navPrev, $navToday, $netTotal, $th, $tm, $totalNet, $totalPay); ?>
