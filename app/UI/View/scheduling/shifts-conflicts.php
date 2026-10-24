<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Table;
use kintai\UI\Components\Alert;

/** @var array  $conflicts */
/** @var array  $too_short */
/** @var array  $stores_min_map */
/** @var array  $users_map */
/** @var array  $stores_map */
/** @var array  $types_map */
/** @var int    $filter_store_id */
/** @var string $filter_month */

$_flashConflict = \kintai\UI\Components\Flash::fromQuery('success', [
    'conflict_resolved'  => __('conflict_resolved_ok'),
    'conflicts_resolved' => fn() => __('conflicts_resolved_ok', ['n' => (int) ($_GET['count'] ?? 0)]),
]);
$_successMsg = $_flashConflict->render();

$byUser = [];
foreach ($conflicts as $c) {
    $byUser[$c['user_id']][] = $c;
}
?>
<div class="page-header">
    <h2 class="page-header__title">
        ⚡ <?= __('shift_conflicts_title') ?>
        <?php if (count($conflicts) > 0): ?>
            <span class="page-count badge badge--danger"><?= count($conflicts) ?></span>
        <?php endif; ?>
    </h2>
    <div class="page-header__actions">
        <a href="<?= route_url('admin.shifts') ?><?= $filter_store_id ? '?store_id=' . $filter_store_id : '' ?><?= $filter_month ? ($filter_store_id ? '&' : '?') . 'month=' . $filter_month : '' ?>" class="btn btn--ghost">← <?= __('shifts') ?></a>
    </div>
</div>

<div class="card card--filters mb-sm">
    <form method="GET" action="" class="filter-bar">
        <div class="shifts-filters__row">
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="cf-month"><?= __('month') ?></label>
                <input type="month" id="cf-month" name="month"
                       value="<?= htmlspecialchars($filter_month) ?>"
                       class="form-control form-control-sm"
                       data-submit-on-change>
            </div>
            <?php if (count($stores_map) > 1): ?>
            <div class="shifts-filters__group">
                <label class="shifts-filters__label" for="cf-store"><?= __('store') ?></label>
                <select id="cf-store" name="store_id" class="form-control form-control-sm" data-submit-on-change>
                    <option value="0"><?= __('all_stores') ?></option>
                    <?php foreach ($stores_map as $sid => $sname): ?>
                        <option value="<?= $sid ?>" <?= $filter_store_id === $sid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="shifts-filters__actions">
                <a href="<?= route_url('admin.shifts.conflicts') ?>" class="btn btn--ghost btn--sm"><?= __('reset') ?></a>
            </div>
        </div>
    </form>
</div>

<?= $_successMsg ?>

<?php if (empty($conflicts)): ?>
    <div class="alert alert--success">✅ <?= __('no_conflicts_found') ?></div>
<?php else:
    $sameMonthConflicts = array_filter($conflicts, function ($c) {
        $monthA = substr($c['shift_a']['shift_date'] ?? '', 0, 7);
        $monthB = substr($c['shift_b']['shift_date'] ?? '', 0, 7);
        return $monthA !== '' && $monthA === $monthB;
    });
?>
    <?php
    $resolveBtn = '';
    if ($filter_month !== '' && count($sameMonthConflicts) > 0):
        $resolveBtn = '<form method="POST" action="' . route_url('admin.shifts.resolve_newer') . '" class="form-inline"'
            . ' data-confirm="' . htmlspecialchars(__('resolve_all_month') . ' (' . count($sameMonthConflicts) . ') ?', ENT_QUOTES) . '">'
            . csrf_field()
            . '<input type="hidden" name="bulk" value="1">'
            . '<input type="hidden" name="month" value="' . htmlspecialchars($filter_month) . '">'
            . '<input type="hidden" name="store_id" value="' . $filter_store_id . '">'
            . Button::make('⚡ ' . __('resolve_all_month') . ' (' . count($sameMonthConflicts) . ')')->warning()->sm()->submit()->render()
            . '</form>';
    endif;
    echo Alert::make(__('n_conflicts_detected', ['n' => count($conflicts)]))->danger()->render();
    echo $resolveBtn;
    ?>

    <?php foreach ($byUser as $userId => $userConflicts): ?>
    <?php $userName = htmlspecialchars($users_map[$userId] ?? ('#' . $userId)); ?>

    <div class="conflict-group mb-sm">
        <div class="conflict-group__header">
            <span class="conflict-user-name">👤 <a href="<?= $BASE_URL ?>/admin/users/<?= employee_segment((int) $userId) ?>/edit"><?= $userName ?></a></span>
            <?= Badge::make((string) count($userConflicts))->danger()->render() ?>
        </div>

        <?php foreach ($userConflicts as $c):
            $sa = $c['shift_a'];
            $sb = $c['shift_b'];
            $overlapMin   = (int) $c['overlap_minutes'];
            $overlapH     = intdiv($overlapMin, 60);
            $overlapM     = $overlapMin % 60;
            $overlapLabel = $overlapH > 0 ? "{$overlapH}h{$overlapM}min" : "{$overlapM}min";
            $storeA = htmlspecialchars($stores_map[(int) ($sa['store_id'] ?? 0)] ?? '—');
            $storeB = htmlspecialchars($stores_map[(int) ($sb['store_id'] ?? 0)] ?? '—');

            $monthA   = substr($sa['shift_date'] ?? '', 0, 7);
            $monthB   = substr($sb['shift_date'] ?? '', 0, 7);
            $sameMth  = $monthA !== '' && $monthA === $monthB;
            $idNewer  = max((int) $sa['id'], (int) $sb['id']);
            $idOlder  = min((int) $sa['id'], (int) $sb['id']);
        ?>
        <div class="conflict-card">
            <div class="conflict-card__overlap">
                ⚡ <?= __('overlap_duration', ['duration' => $overlapLabel]) ?>
            </div>

            <div class="conflict-card__shifts">
                <div class="conflict-shift conflict-shift--a">
                    <div class="conflict-shift__label"><?= __('conflict_shift_a') ?> — #<?= (int) $sa['id'] ?></div>
                    <div class="conflict-shift__info">
                        <strong><?= htmlspecialchars($sa['shift_date'] ?? '') ?></strong>
                        <?= htmlspecialchars($sa['start_time'] ?? '') ?> – <?= htmlspecialchars($sa['end_time'] ?? '') ?>
                        <?= !empty($sa['cross_midnight']) ? '🌙' : '' ?>
                    </div>
                    <?php if (count($stores_map) > 1): ?>
                    <div class="conflict-shift__store td-muted"><?= $storeA ?></div>
                    <?php endif; ?>
                    <?php $typeA = $types_map[(int) ($sa['shift_type_id'] ?? 0)] ?? null; ?>
                    <?php if ($typeA): ?>
                    <div class="conflict-shift__type">
                        <?= Badge::make($typeA['name'] ?? '')->render() ?>
                    </div>
                    <?php endif; ?>
                    <div class="conflict-shift__actions">
                        <a href="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sa['id'] ?>/edit"
                           class="btn btn--ghost btn--xs">✏️ <?= __('edit') ?></a>
                        <form method="POST" action="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sa['id'] ?>/delete"
                              class="form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                            <?= Button::make('🗑')->danger()->xs()->submit()->render() ?>
                        </form>
                    </div>
                </div>

                <div class="conflict-card__vs">↔</div>

                <div class="conflict-shift conflict-shift--b">
                    <div class="conflict-shift__label"><?= __('conflict_shift_b') ?> — #<?= (int) $sb['id'] ?></div>
                    <div class="conflict-shift__info">
                        <strong><?= htmlspecialchars($sb['shift_date'] ?? '') ?></strong>
                        <?= htmlspecialchars($sb['start_time'] ?? '') ?> – <?= htmlspecialchars($sb['end_time'] ?? '') ?>
                        <?= !empty($sb['cross_midnight']) ? '🌙' : '' ?>
                    </div>
                    <?php if (count($stores_map) > 1): ?>
                    <div class="conflict-shift__store td-muted"><?= $storeB ?></div>
                    <?php endif; ?>
                    <?php $typeB = $types_map[(int) ($sb['shift_type_id'] ?? 0)] ?? null; ?>
                    <?php if ($typeB): ?>
                    <div class="conflict-shift__type">
                        <?= Badge::make($typeB['name'] ?? '')->render() ?>
                    </div>
                    <?php endif; ?>
                    <div class="conflict-shift__actions">
                        <a href="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sb['id'] ?>/edit"
                           class="btn btn--ghost btn--xs">✏️ <?= __('edit') ?></a>
                        <form method="POST" action="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sb['id'] ?>/delete"
                              class="form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
                            <?= Button::make('🗑')->danger()->xs()->submit()->render() ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="conflict-card__suggest">
                <span class="text-sm-muted">💡 <?= __('conflict_suggestions') ?></span>
                <?php if ($sameMth): ?>
                <form method="POST" action="<?= route_url('admin.shifts.resolve_newer') ?>"
                      class="form-inline"
                      data-confirm="<?= htmlspecialchars(__('keep_newer_shift') . ' #' . $idNewer . ' ?') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_keep"   value="<?= $idNewer ?>">
                    <input type="hidden" name="id_delete" value="<?= $idOlder ?>">
                    <input type="hidden" name="month"     value="<?= htmlspecialchars($monthA) ?>">
                    <input type="hidden" name="store_id"  value="<?= $filter_store_id ?>">
                    <?= Button::make('⚡ ' . __('keep_newer_shift') . ' (#' . $idNewer . ')')->warning()->xs()->submit()->render() ?>
                </form>
                <?php endif; ?>
                <a href="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sa['id'] ?>/edit" class="btn btn--ghost btn--xs">
                    <?= __('adjust_hours_shift_a') ?>
                </a>
                <a href="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sb['id'] ?>/edit" class="btn btn--ghost btn--xs">
                    <?= __('adjust_hours_shift_b') ?>
                </a>
                <a href="<?= $BASE_URL ?>/admin/shifts/<?= (int) $sb['id'] ?>/edit?reassign=1" class="btn btn--secondary btn--xs">
                    <?= __('reassign_shift_b') ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($too_short)): ?>
<div class="section-title section-title--flex mt-md mb-sm">
    <h3>⏱ <?= __('too_short_shifts_title') ?></h3>
    <?= Badge::make((string) count($too_short))->warning()->render() ?>
</div>

<?= Alert::make(__('too_short_shifts_info', ['n' => count($too_short)]))->warning()->render() ?>

<div class="card">
<?php
$tsTable = Table::make()
    ->data($too_short)
    ->column(__('date'), fn($s) => '<span class="td-nowrap">' . htmlspecialchars($s['shift_date'] ?? '') . '</span>')
    ->column(__('employee'), function($s) use ($BASE_URL, $users_map) {
        $uid = (int) ($s['user_id'] ?? 0);
        return '<a href="' . htmlspecialchars($BASE_URL . '/admin/users/' . employee_segment($uid) . '/edit') . '">'
            . htmlspecialchars($users_map[$uid] ?? ('#' . ($s['user_id'] ?? '?'))) . '</a>';
    })
    ->column(__('start') . ' – ' . __('end'), function($s) use ($types_map) {
        $html = '<span class="td-nowrap">' . htmlspecialchars($s['start_time'] ?? '') . ' – ' . htmlspecialchars($s['end_time'] ?? '') . '</span>';
        $html .= !empty($s['cross_midnight']) ? ' 🌙' : '';
        $typeObj = $types_map[(int) ($s['shift_type_id'] ?? 0)] ?? null;
        if ($typeObj) {
            $html .= ' <span class="type-badge" data-color="' . htmlspecialchars($typeObj['color'] ?? '') . '">'
                . htmlspecialchars($typeObj['name'] ?? '') . '</span>';
        }
        return $html;
    })
    ->column(__('duration'), function($s) {
        $dur = (int) ($s['duration_minutes'] ?? 0);
        $h = intdiv($dur, 60);
        $m = $dur % 60;
        $l = $h > 0 ? "{$h}h{$m}min" : "{$m}min";
        return Badge::make($l)->danger()->render();
    })
    ->column(__('minimum'), function($s) use ($stores_min_map) {
        $min = (int) ($stores_min_map[(int) ($s['store_id'] ?? 0)] ?? 0);
        $h = intdiv($min, 60);
        $m = $min % 60;
        return $h > 0 ? "{$h}h{$m}min" : "{$m}min";
    });
if (count($stores_map) > 1):
    $tsTable->text(__('store'), fn($s) => $stores_map[(int) ($s['store_id'] ?? 0)] ?? '#' . (int)($s['store_id'] ?? 0));
endif;
$tsTable->column('', function($s) use ($BASE_URL) {
    $id = (int) $s['id'];
    return '<a href="' . htmlspecialchars($BASE_URL . '/admin/shifts/' . $id . '/edit') . '" class="btn btn--ghost btn--xs">✏️ ' . __('edit') . '</a>'
        . '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/shifts/' . $id . '/delete') . '" class="form-inline">'
        . csrf_field()
        . '<input type="hidden" name="redirect_to" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '">'
        . Button::make('🗑')->danger()->xs()->submit()->render()
        . '</form>';
});
echo $tsTable->render();
?>
</div>
<?php endif; ?>

<script src="<?= $BASE_URL ?>/assets/js/modules/type-badges.js"></script>
