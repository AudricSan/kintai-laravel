<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Flash;
use kintai\UI\Components\Table;

/** @var array  $users */
/** @var array  $user_stats */
/** @var array  $available_stores */
/** @var array  $store_names */
/** @var array  $user_store_ids */
/** @var array  $user_store_map */
/** @var string $month_label */
/** @var string $store_currency */
/** @var string $sort */
/** @var int    $filter_store_id */
/** @var string $filter_search */

$store_currency   ??= 'JPY';
$store_currency_symbol_style ??= 'kanji';
$sort             ??= 'name_asc';
$filter_store_id  ??= 0;
$filter_search    ??= '';
$available_stores ??= [];
$store_names      ??= [];
$user_store_ids   ??= [];
$user_store_map   ??= [];
$can              = $user_can ?? fn(string $k): bool => true;

$activeFilters = array_filter([
    'store_id' => $filter_store_id ?: null,
], fn($v) => $v !== null);

echo Flash::fromQuery('success', [
    'created' => __('operation_success'),
    'updated' => __('user_updated'),
    'deleted' => __('operation_success'),
])->render();
?>
<?php
$exportQuery = $filter_store_id !== 0 ? '?store_id=' . $filter_store_id : '';
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('users') ?> <span class="page-count">(<?= count($users) ?>)</span></h2>
    <div class="page-header__actions">
        <?php if ($can('employees.create')): ?>
        <?= Button::make('+ ' . __('new_user'))->primary()->link(route_url('admin.users.create'))->render() ?>
        <?php endif; ?>
        <?= Button::make('PDF')->ghost()->sm()->link(route_url('admin.users.export_pdf') . $exportQuery)->attrs(['target' => '_blank'])->render() ?>
        <?= Button::make('JSON')->ghost()->sm()->link(route_url('admin.users.export_json') . $exportQuery)->render() ?>
    </div>
</div>

<div class="card card--filters mb-sm">
    <form method="GET" action="" class="filter-bar">
        <div class="shifts-filters__row">
            <?php if (count($available_stores) > 1 || $filter_store_id !== 0): ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="uf-store"><?= __('store') ?></label>
                <select id="uf-store" name="store_id" class="form-control form-control-sm" data-submit-on-change>
                    <option value="0"><?= __('all_stores') ?></option>
                    <?php foreach ($available_stores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $filter_store_id === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="-1" <?= $filter_store_id === -1 ? 'selected' : '' ?>><?= __('without_store') ?></option>
                </select>
            </div>
            <?php endif; ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="uf-search"><?= __('search') ?></label>
                <input type="text" id="uf-search" name="search" class="form-control form-control-sm" data-client-filter="1" value="<?= htmlspecialchars($filter_search) ?>" placeholder="<?= __('search_user_placeholder') ?>">
            </div>
            <div class="shifts-filters__actions">
                <?php if ($filter_search !== '' || $filter_store_id !== 0): ?>
                    <a href="?sort=<?= htmlspecialchars($sort) ?>" class="btn btn--ghost btn--sm"><?= __('reset') ?></a>
                <?php endif; ?>
            </div>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        </div>
    </form>
</div>

<div class="card">
<?= Table::make()
    ->attrs(['id' => 'users-table'])
    ->data($users)
    ->emptyMessage(__('none'))
    ->currentSort($sort)
    ->filters($activeFilters)
    ->rowUrl(fn($u) => $BASE_URL . '/admin/users/' . employee_segment((int) $u['id']) . '/edit')
    ->rowAttrs(fn($u) => [
        'data-search-text'    => implode('|', [
            trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '')),
            $u['display_name'] ?? '',
            $u['employee_code'] ?? '',
            $u['email'] ?? '',
        ]),
        'data-search-reading' => ($u['furigana_last_name'] ?? '') . ($u['furigana_first_name'] ?? ''),
    ])
    ->column('#', fn($u) => (string) (int) $u['id'])
    ->sortable(__('name'), 'name', function($u) use ($BASE_URL) {
        $uid = (int) $u['id'];
        $name = htmlspecialchars($u['display_name'] ?? '');
        $full = htmlspecialchars(trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '')));
        $initials = htmlspecialchars(strtoupper(
            mb_substr((string) ($u['last_name'] ?? ''), 0, 1) . mb_substr((string) ($u['first_name'] ?? ''), 0, 1)
        ));
        if ($initials === '') {
            $initials = htmlspecialchars(mb_substr(strip_tags((string) ($u['display_name'] ?? '')), 0, 2));
        }
        $chipColor = htmlspecialchars($u['color'] ?? '#6c5ce7');
        return '<div class="avatar-chip-cell">'
            . '<span class="avatar-chip ' . style_class(['--chip-bg' => $chipColor]) . '">' . $initials . '</span>'
            . '<div><a href="' . htmlspecialchars($BASE_URL . '/admin/users/' . employee_segment($uid) . '/edit') . '"><strong>' . $name . '</strong></a>'
            . ($full !== $name ? '<div class="text-hint">' . $full . '</div>' : '')
            . '</div></div>';
    })
    ->sortableText(__('email'), 'email', fn($u) => $u['email'] ?? '')
    ->sortable(__('role'), 'role', fn($u) => !empty($u['is_admin']) ? Badge::make('Admin')->admin()->render() : Badge::make('Staff')->staff()->render())
    ->column(__('store'), function($u) use ($user_store_ids, $store_names) {
        $uStoreIds = $user_store_ids[(int) $u['id']] ?? [];
        if (empty($uStoreIds)) return '<span class="text-muted">—</span>';
        $html = '';
        foreach ($uStoreIds as $usid) {
            $sname = $store_names[$usid] ?? '';
            if ($sname) $html .= Badge::make($sname)->store()->render() . ' ';
        }
        return $html;
    })
    ->sortable(__('status'), 'status', fn($u) =>
        !empty($u['is_active']) && empty($u['deleted_at'])
            ? Badge::make(__('active'))->active()->render()
            : Badge::make(__('inactive'))->inactive()->render()
    )
    ->sortable(__('hours_per_month_short'), 'hours', function($u) use ($user_stats) {
        $hm = ($user_stats ?? [])[(int) $u['id']]['hours_month'] ?? 0;
        return $hm > 0 ? '<strong>' . number_format((float) $hm, 1) . '</strong> ' . __('hours_unit') : '<span class="text-muted">—</span>';
    })
    ->column(__('avg_per_week_short'), function($u) use ($user_stats) {
        $hw = ($user_stats ?? [])[(int) $u['id']]['hours_week'] ?? 0;
        return $hw > 0 ? number_format((float) $hw, 1) . ' ' . __('hours_unit') : '<span class="text-muted">—</span>';
    })
    ->column(__('estimated_pay'), function($u) use ($user_stats, $store_currency, $store_currency_symbol_style) {
        $pay = ($user_stats ?? [])[(int) $u['id']]['estimated_pay'] ?? 0;
        return $pay > 0 ? format_currency((float) $pay, $store_currency, $store_currency_symbol_style) : '<span class="text-muted" title="' . __('no_rate_configured') . '">—</span>';
    })
    ->column(__('date'), fn($u) => '<span class="td-date-muted">' . htmlspecialchars(substr($u['created_at'] ?? '', 0, 10)) . '</span>')
    ->column(__('actions'), function($u) use ($BASE_URL, $user_store_map, $can) {
        $uid = (int) $u['id'];
        if (!isset($user_store_map[$uid])) {
            return '';
        }
        $sId = $user_store_map[$uid];
        $panel = '';
        if ($can('payroll.view')) {
            $panel .= '<a href="' . $BASE_URL . '/admin/stores/' . store_segment($sId) . '/employee-report/' . employee_segment($uid) . '/stats" class="row-actions__link">📊 ' . htmlspecialchars(__('employee_stats')) . '</a>';
        }
        // Liens des bundles (rapport de salaire, démission… — Bundle::actions(), contrat 1.6).
        foreach (\kintai\Core\Extension\Actions::current()->links(\kintai\Contract\Extension\Action::EMPLOYEE_ROW, ['user_id' => $uid, 'store_id' => $sId], $can) as $_a) {
            $panel .= '<a href="' . htmlspecialchars($_a['href']) . '" class="row-actions__link' . ($_a['style'] === 'danger' ? ' row-actions__link--danger' : '') . '">'
                . ($_a['icon'] !== '' ? $_a['icon'] . ' ' : '') . htmlspecialchars($_a['label']) . '</a>';
        }
        if ($panel === '') {
            return '';
        }
        return '<div class="row-actions">'
            . '<button type="button" class="row-actions__trigger" aria-haspopup="true" aria-label="' . htmlspecialchars(__('actions')) . '">⋮</button>'
            . '<div class="row-actions__panel">' . $panel . '</div>'
            . '</div>';
    })
    ->render()
?></div>

<script src="<?= $BASE_URL ?>/assets/js/modules/kana-search.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/users-list-search.js"></script>
