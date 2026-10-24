<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Table;

/**
 * Page de statistiques individuelles d'un employé
 *
 * @var array  $store
 * @var array  $user
 * @var array  $membership
 * @var int    $period
 * @var string $since
 * @var string $today
 * @var string $currency
 * @var int    $totalShifts
 * @var float  $grossHours
 * @var float  $netHours
 * @var float  $cost
 * @var bool   $anyRate
 * @var int    $workDays
 * @var int    $absDays
 * @var int    $maxConsec
 * @var int    $swapCount
 * @var array  $monthlyHours   ['YYYY-MM' => float]
 * @var array  $typeHours      ['type name' => float]
 * @var array  $dowChart       ['Lun' => float, ...]
 * @var array  $recentShifts
 * @var array  $storeTypesMap
 * @var string $BASE_URL
 */

$empName  = trim(($user['last_name'] ?? '') . ' ' . ($user['first_name'] ?? ''))
    ?: ($user['display_name'] ?? ($user['email'] ?? ''));
$currencyStyle = store_currency_style($store);
$roleLabel = $membership['role_name'] ?? '—';

// Garde : la vue peut être rendue deux fois dans un même processus ; une seconde déclaration de ces fonctions
// globales serait une erreur fatale (« Cannot redeclare »).
if (!function_exists('estatBar')) {
    function estatBar(array $data, string $color = 'var(--color-primary)', int $maxH = 80): string {
        if (!$data || max(array_values($data)) == 0) {
            return '<em class="sstat-empty">' . htmlspecialchars(__('no_data')) . '</em>';
        }
        $max = max(array_values($data)) ?: 1;
        $out = '<div class="sstat-bar-chart">';
        foreach ($data as $label => $val) {
            $h = round($val / $max * $maxH);
            $display = round($val, 1) . __('hours_unit');
            $out .= '<div class="sstat-bar-col" title="' . htmlspecialchars($label) . ': ' . $display . '">'
                . '<div class="sstat-bar-value">' . $display . '</div>'
                . '<div class="sstat-bar ' . style_class(['height' => $h . 'px', 'background' => $color]) . '"></div>'
                . '<div class="sstat-bar-label">' . htmlspecialchars($label) . '</div>'
                . '</div>';
        }
        return $out . '</div>';
    }

    function estatHours(float $hours): string {
        $h = intdiv((int) round($hours * 60), 60);
        $m = (int) round($hours * 60) % 60;
        return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    function estatDate(string $date): string {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('d/m/Y') : $date;
    }
}

$reportUrl = $BASE_URL . '/admin/stores/' . store_segment((int) $store['id']) . '/employee-report?period=' . $period;

?>

<div class="page-header">
  <h2 class="page-header__title">
    <?= __('employee_stats') ?> — <?= htmlspecialchars($empName) ?>
    <span class="page-count"><?= htmlspecialchars($store['name'] ?? '') ?></span>
  </h2>
  <div class="page-header__actions">
    <?php // Liens des bundles pour cet employé dans ce magasin (rapport de salaire… — Bundle::actions(), contrat 1.6) :
    // même emplacement qu'une ligne du rapport employés, style par défaut = bouton principal de l'en-tête. ?>
    <?php foreach (\kintai\Core\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::STORE_REPORT_ROW, ['store_id' => (int) $store['id'], 'user_id' => (int) $user['id'], 'period' => (int) $period], $user_can ?? fn(string $k): bool => true) as $_a): ?>
    <?= Button::make(($_a['icon'] !== '' ? $_a['icon'] . ' ' : '') . $_a['label'])->{$_a['style'] === 'danger' ? 'danger' : ($_a['style'] === 'ghost' ? 'ghost' : 'primary')}()->sm()->link($_a['href'])->render() ?>
    <?php endforeach; ?>
    <?= Button::make('← ' . __('back_to_report'))->ghost()->sm()->link(htmlspecialchars($reportUrl))->render() ?>
  </div>
</div>

<!-- Navigation des périodes -->
<div class="sstat-period-nav">
  <?php foreach ([7 => __('period_7d'), 30 => __('period_30d'), 90 => __('period_3m'), 180 => __('period_6m'), 365 => __('period_1y')] as $d => $label): ?>
    <a href="?period=<?= $d ?>" class="<?= $period == $d ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<!-- Profil employé -->
<div class="sstat-section">
  <div class="sstat-section-title"><?= __('employee_name') ?></div>
  <div class="estat-profile">
    <div class="estat-avatar <?= style_class(['background' => $user['color'] ?? '#3B82F6']) ?>">
      <?= strtoupper(substr($empName, 0, 1)) ?>
    </div>
    <div class="estat-profile-info">
      <div class="estat-profile-name"><?= htmlspecialchars($empName) ?></div>
      <div class="estat-profile-meta">
        <?= Badge::make($roleLabel)->variant(!empty($membership['role_is_managing']) ? 'warning' : 'staff')->render() ?>
        <?php if (!empty($user['employee_code'])): ?>
          <span class="estat-code">N° <?= htmlspecialchars($user['employee_code']) ?></span>
        <?php endif; ?>
        <span class="estat-email"><?= htmlspecialchars($user['email'] ?? '') ?></span>
      </div>
    </div>
  </div>
</div>

<!-- KPI cards -->
<div class="sstat-section">
  <div class="sstat-section-title"><?= __('on_period') ?> — <?= htmlspecialchars($since) ?> / <?= htmlspecialchars($today) ?></div>
  <div class="sstat-grid">

    <div class="sstat-card">
      <div class="sstat-card__value text-primary"><?= $totalShifts ?></div>
      <div class="sstat-card__label"><?= __('shifts_col') ?></div>
      <div class="sstat-card__sub"><?= __('on_period') ?></div>
    </div>

    <div class="sstat-card">
      <div class="sstat-card__value text-primary"><?= $workDays ?></div>
      <div class="sstat-card__label"><?= __('kpi_work_days') ?></div>
      <div class="sstat-card__sub"><?= __('on_period') ?></div>
    </div>

    <div class="sstat-card">
      <div class="sstat-card__value"><?= estatHours($grossHours) ?></div>
      <div class="sstat-card__label"><?= __('gross_h_col') ?></div>
      <div class="sstat-card__sub"><?= number_format($grossHours, 1) ?> <?= __('hours_unit') ?></div>
    </div>

    <div class="sstat-card">
      <div class="sstat-card__value text-primary"><?= estatHours($netHours) ?></div>
      <div class="sstat-card__label"><?= __('net_h_col') ?></div>
      <div class="sstat-card__sub"><?= number_format($netHours, 1) ?> <?= __('hours_unit') ?></div>
    </div>

    <?php if ($anyRate): ?>
    <div class="sstat-card">
      <div class="sstat-card__value text-success"><?= format_currency($cost, $currency, $currencyStyle) ?></div>
      <div class="sstat-card__label"><?= __('estimated_cost_col') ?></div>
      <div class="sstat-card__sub"><?= __('on_period') ?></div>
    </div>
    <?php endif; ?>

    <div class="sstat-card">
      <div class="sstat-card__value <?= $absDays > 3 ? 'erep-val--warn' : '' ?>"><?= $absDays ?: '—' ?></div>
      <div class="sstat-card__label"><?= __('kpi_absence_days') ?></div>
      <div class="sstat-card__sub"><?= __('approved_days') ?></div>
    </div>

    <div class="sstat-card">
      <div class="sstat-card__value <?= $maxConsec >= 6 ? 'erep-val--warn' : '' ?>"><?= $maxConsec ?: '—' ?></div>
      <div class="sstat-card__label"><?= __('kpi_max_consec') ?></div>
      <div class="sstat-card__sub"><?= __('max_consec_col') ?></div>
    </div>

    <div class="sstat-card">
      <div class="sstat-card__value"><?= $swapCount ?: '—' ?></div>
      <div class="sstat-card__label"><?= __('swaps_col') ?></div>
      <div class="sstat-card__sub"><?= __('on_period') ?></div>
    </div>

  </div>
</div>

<?php if (!$anyRate): ?>
<?= Alert::make(__('no_rate_cost_hint'))->info()->render() ?>
<?php endif; ?>

<!-- Graphique mensuel (12 derniers mois) -->
<div class="sstat-section">
  <div class="sstat-section-title"><?= __('monthly_hours_chart') ?></div>
  <?php
    $monthLabels = [];
    foreach (array_keys($monthlyHours) as $ym) {
        $dt = \DateTime::createFromFormat('Y-m', $ym);
        $monthLabels[$dt ? month_label($ym) : $ym] = round($monthlyHours[$ym], 1);
    }
  ?>
  <?= estatBar($monthLabels, 'var(--color-primary)') ?>
</div>

<!-- Répartition par type de shift -->
<?php if (!empty($typeHours)): ?>
<div class="sstat-section">
  <div class="sstat-section-title"><?= __('hours_by_type_chart') ?></div>
  <?= estatBar(array_map(fn($h) => round($h, 1), $typeHours), 'var(--color-warning)') ?>
</div>
<?php endif; ?>

<!-- Répartition par jour de semaine -->
<div class="sstat-section">
  <div class="sstat-section-title"><?= __('hours_by_dow_chart') ?></div>
  <?= estatBar(array_map(fn($h) => round($h, 1), $dowChart), 'var(--color-info, #6366f1)') ?>
</div>

<!-- Shifts récents -->
<div class="sstat-section">
  <div class="sstat-section-title"><?= __('recent_shifts') ?></div>

  <?= Table::make()
      ->data($recentShifts)
      ->column(__('date'), fn($s) => estatDate($s['shift_date'] ?? ''), 'td-nowrap')
      ->column(__('shift_type'), function($s) use ($storeTypesMap) {
          $tid = (int) ($s['shift_type_id'] ?? 0);
          return htmlspecialchars($tid && isset($storeTypesMap[$tid]) ? $storeTypesMap[$tid]['name'] : '—');
      })
      ->column(__('start_time'), fn($s) => '<span class="td-mono">' . htmlspecialchars(substr($s['start_time'] ?? '00:00', 0, 5)) . '</span>')
      ->column(__('end_time'), fn($s) => '<span class="td-mono">' . htmlspecialchars(substr($s['end_time'] ?? '00:00', 0, 5)) . '</span>')
      ->column(__('gross_h_col'), fn($s) => '<span class="td-right td-mono">' . estatHours(((int) ($s['duration_minutes'] ?? 0)) / 60) . '</span>', 'td-right')
      ->column(__('net_h_col'), function($s) {
          $nMin = max(0, (int) ($s['duration_minutes'] ?? 0) - (int) ($s['pause_minutes'] ?? 0));
          return '<span class="td-right td-mono">' . estatHours($nMin / 60) . '</span>';
      }, 'td-right')
      ->emptyMessage(__('no_data'))
      ->render();
  ?>
</div>

