<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * @var string $mode 'create'|'edit'
 * @var array  $shift_type
 * @var array  $all_stores
 */
$action = $mode === 'edit'
    ? $BASE_URL . '/admin/shift-types/' . (int) $shift_type['id'] . '/edit'
    : route_url('admin.shift_types.create');

echo Flash::fromQuery('error', [
    'store_required' => __('val_store_required'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= $mode === 'edit' ? __('edit_shift_type') : __('new_shift_type') ?></h2>
    <div class="page-header__actions">
        <a href="<?= back_url(route_url('admin.shift_types')) ?>" class="btn btn--ghost">← <?= __('back') ?></a>
    </div>
</div>

<?php
ob_start();
echo '<form method="POST" action="' . htmlspecialchars($action) . '">';
echo csrf_field();
include __DIR__ . '/../_partials/_form-shift-type.php';
echo '<div class="form-actions">';
echo Button::make($mode === 'edit' ? __('save') : __('new_type'))->primary()->submit()->render();
echo ' <a href="' . back_url(route_url('admin.shift_types')) . '" class="btn btn--ghost">' . __('cancel') . '</a>';
echo '</div></form>';
echo Card::make()->body(ob_get_clean())->render();
?>
