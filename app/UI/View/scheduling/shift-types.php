<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Table;
use kintai\UI\Components\Flash;

/** @var array  $shift_types */
/** @var array  $type_store_ids   id de type => IDs de stores affectés */
/** @var array  $all_stores       stores visibles (scope du gestionnaire), pour les colonnes de la matrice */
/** @var string $sort */
/** @var int[]  $locked_type_ids  types partagés avec un magasin non géré : ni modifiables ni supprimables ici */
$sort           ??= 'name_asc';
$locked_type_ids ??= [];
$type_store_ids ??= [];
$all_stores     ??= [];

echo Flash::fromQuery('success', [
    'created'         => __('shift_type_created'),
    'updated'         => __('shift_type_updated'),
    'deleted'         => __('shift_type_deleted'),
    'store_enabled'   => __('shift_type_store_enabled'),
    'store_disabled'  => __('shift_type_store_disabled'),
])->render();

echo Flash::fromQuery('error', [
    'store_required' => __('val_store_required'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('shift_types') ?> <span class="page-count">(<?= count($shift_types) ?>)</span></h2>
    <div class="page-header__actions">
        <?= Button::make('+ ' . __('new_type'))->primary()->link(route_url('admin.shift_types.create'))->render() ?>
    </div>
</div>

<div class="card shift-types-matrix">
    <div class="card-header"><h3 class="card-title"><?= __('stores_plural') ?></h3></div>
    <div class="table-wrap shift-types-matrix__scroll">
        <table class="data-table matrix-table">
            <thead>
                <tr>
                    <th class="td-center"><?= __('shift_types') ?></th>
                    <?php foreach ($all_stores as $s): ?>
                    <th class="td-center matrix-table__store-col" title="<?= htmlspecialchars($s['name'] ?? '') ?>">
                        <?= htmlspecialchars($s['name'] ?? '') ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($shift_types === [] || $all_stores === []): ?>
                <tr><td colspan="<?= count($all_stores) + 1 ?>" class="text-muted"><?= __('no_shift_type_found') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($shift_types as $t): ?>
                <?php $tid = (int) $t['id']; ?>
                <tr>
                    <td class="td-center td-nowrap">
                        <span class="color-swatch <?= style_class(['background' => $t['color'] ?? '#ccc']) ?>"></span>
                        <?= htmlspecialchars($t['name'] ?? '') ?>
                    </td>
                    <?php foreach ($all_stores as $s): ?>
                    <?php $sid = (int) $s['id']; ?>
                    <td class="td-center">
                        <form method="POST" action="<?= $BASE_URL ?>/admin/shift-types/<?= $tid ?>/toggle-store" class="form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="store_id" value="<?= $sid ?>">
                            <label class="form-toggle">
                                <input type="checkbox" name="enabled" value="1" class="form-toggle__input"
                                       <?= in_array($sid, $type_store_ids[$tid] ?? [], true) ? 'checked' : '' ?>
                                       data-submit-on-change>
                                <span class="form-toggle__track"></span>
                            </label>
                        </form>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
<?= Table::make()
    ->data($shift_types)
    ->emptyMessage(__('no_shift_type_found'))
    ->currentSort($sort)
    ->rowUrl(fn($t) => in_array((int) $t['id'], $locked_type_ids, true) ? '' : $BASE_URL . '/admin/shift-types/' . (int) $t['id'] . '/edit')
    ->column('#', fn($t) => (string) (int) $t['id'])
    ->sortable(__('code'), 'code', fn($t) => '<code class="code-sm">' . htmlspecialchars($t['code'] ?? '') . '</code>')
    ->sortable(__('name'), 'name', fn($t) => '<strong>' . htmlspecialchars($t['name'] ?? '') . '</strong>')
    ->text(__('start'), fn($t) => $t['start_time'] ?? '')
    ->text(__('end'), fn($t) => $t['end_time'] ?? '')
    ->column(__('color'), fn($t) =>
        '<span class="color-preview"><span class="color-swatch ' . style_class(['background' => $t['color'] ?? '#ccc']) . '"></span><code class="color-code">' . htmlspecialchars($t['color'] ?? '') . '</code></span>'
    )
    ->sortable(__('status'), 'status', fn($t) =>
        !empty($t['is_active'])
            ? Badge::make(__('active'))->active()->render()
            : Badge::make(__('inactive'))->inactive()->render()
    )
    ->column(__('actions'), function($t) use ($BASE_URL, $locked_type_ids) {
        $id = (int) $t['id'];
        if (in_array($id, $locked_type_ids, true)) {
            return '<span class="text-sm-muted" title="' . htmlspecialchars(__('error_shared_shift_type'), ENT_QUOTES) . '">'
                . __('shift_type_shared_locked') . '</span>';
        }
        $html = '<div class="btn-group">';
        $html .= '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/shift-types/' . $id . '/delete') . '" class="form-inline">';
        $html .= csrf_field();
        $html .= Button::make(__('delete'))->danger()->sm()->submit()->attrs(['data-confirm' => __('confirm_delete_shift_type')])->render();
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    })
    ->render()
?></div>
