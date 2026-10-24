<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Table;
use kintai\UI\Components\Flash;

/** @var array  $stores */
/** @var string $sort */
$sort ??= 'name_asc';
$can = $user_can ?? fn(string $k): bool => true;

echo Flash::fromQuery('success', [
    'created' => __('store_created'),
    'updated' => __('store_updated'),
    'deleted' => __('store_deleted'),
])->render();

// Couleur de puce déterministe (dérivée du code du magasin) : les stores
// n'ont pas de couleur propre en base, contrairement aux employés — on en
// dérive une pour garder le repère visuel cohérent avec la liste employés.
$storeChipColor = function (string $seed): string {
    $hue = crc32($seed) % 360;
    return "hsl({$hue}, 62%, 52%)";
};
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('stores_plural') ?> <span class="page-count">(<?= count($stores) ?>)</span></h2>
    <div class="page-header__actions">
        <?= $can('stores.create') ? Button::make('+ ' . __('new_store'))->primary()->link(route_url('admin.stores.create'))->render() : '' ?>
    </div>
</div>

<div class="card">
<?= Table::make()
    ->data($stores)
    ->emptyMessage(__('no_store_found'))
    ->currentSort($sort)
    ->rowUrl(fn($s) => $BASE_URL . '/admin/stores/' . store_segment((int) $s['id']) . '/edit')
    ->column('#', fn($s) => (string) (int) $s['id'])
    ->sortable(__('code'), 'code', fn($s) => '<code class="code-sm">' . htmlspecialchars($s['code'] ?? '') . '</code>')
    ->sortable(__('name'), 'name', function($s) use ($storeChipColor) {
        $name = htmlspecialchars($s['name'] ?? '');
        $code = htmlspecialchars($s['code'] ?? '');
        $initials = htmlspecialchars(strtoupper(mb_substr((string) ($s['name'] ?? $code), 0, 2)));
        $color = $storeChipColor((string) ($s['code'] ?? $s['name'] ?? (string) ($s['id'] ?? '')));
        return '<div class="avatar-chip-cell">'
            . '<span class="avatar-chip ' . style_class(['--chip-bg' => $color]) . '">' . $initials . '</span>'
            . '<strong>' . $name . '</strong></div>';
    })
    ->sortableText(__('type'), 'type', fn($s) => $s['type'] ?? '')
    ->column(__('timezone'), fn($s) => '<span class="text-sm-muted">' . htmlspecialchars($s['timezone'] ?? '') . '</span>')
    ->text(__('currency'), fn($s) => currency_symbol($s['currency'] ?? '', store_currency_style($s)))
    ->sortable(__('status'), 'status', fn($s) =>
        (!empty($s['is_active']) && empty($s['deleted_at']))
            ? Badge::make(__('active'))->active()->render()
            : Badge::make(__('inactive'))->inactive()->render()
    )
    ->column(__('actions'), function($s) use ($BASE_URL, $can) {
        $id = (int) $s['id'];
        $panel = '';
        if ($can('payroll.view')) {
            $panel .= '<a href="' . $BASE_URL . '/admin/stores/' . store_segment($id) . '/stats" class="row-actions__link">📊 ' . htmlspecialchars(__('statistics')) . '</a>'
                . '<a href="' . $BASE_URL . '/admin/stores/' . store_segment($id) . '/employee-report" class="row-actions__link">👥 ' . htmlspecialchars(__('employee_report')) . '</a>';
        }
        if ($can('stores.delete')) {
            $panel .= '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/stores/' . store_segment($id) . '/delete') . '" data-confirm="' . htmlspecialchars(__('confirm_delete_store'), ENT_QUOTES) . '">'
                . csrf_field()
                . '<button type="submit" class="row-actions__link row-actions__link--danger">🗑 ' . htmlspecialchars(__('delete')) . '</button>'
                . '</form>';
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
