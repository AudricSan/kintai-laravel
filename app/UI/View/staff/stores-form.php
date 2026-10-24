<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;
use kintai\UI\Components\Table;

/**
 * @var string     $mode
 * @var array      $store
 * @var array      $members
 * @var array      $available
 * @var array      $assignable_roles Rôles dynamiques assignables (table roles)
 * @var int        $default_role_id  Rôle pré-sélectionné dans le formulaire d'ajout
 * @var array|null $enabledFeatures
 */
$members          ??= [];
$available        ??= [];
$assignable_roles ??= [];
$default_role_id  ??= 0;
$can              = $user_can ?? fn(string $k): bool => true;
$action  = $mode === 'edit'
    ? $BASE_URL . '/admin/stores/' . store_segment((int) $store['id']) . '/edit'
    : route_url('admin.stores.create');
$storeId = (int) ($store['id'] ?? 0);

$ded = $deductionSettings ?? [];

echo Flash::fromQuery('success', [
    'updated'        => __('store_updated'),
    'member_added'   => __('member_added'),
    'role_updated'   => __('role_updated'),
    'member_removed' => __('member_removed'),
])->render();
echo Flash::fromQuery('error', [
    'invalid_role' => __('role_invalid'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= $mode === 'edit' ? __('edit_store') : __('new_store') ?></h2>
    <div class="page-header__actions">
        <a href="<?= back_url(route_url('admin.stores')) ?>" class="btn btn--ghost">← <?= __('back') ?></a>
    </div>
</div>

<form method="POST" action="<?= htmlspecialchars($action) ?>">
    <?= csrf_field() ?>
    <?php include __DIR__ . '/../_partials/_form-store.php'; ?>
    <div class="form-actions">
        <?= Button::make($mode === 'edit' ? __('save') : __('new_store'))->primary()->submit()->render() ?>
        <a href="<?= back_url(route_url('admin.stores')) ?>" class="btn btn--ghost"><?= __('cancel') ?></a>
    </div>
</form>

<script id="store-form-i18n" type="application/json"><?= json_encode([
    'active'   => __('active'),
    'inactive' => __('inactive'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/stores-form.js"></script>

<?php if ($mode === 'edit'): ?>
<div class="card card--mt">
    <div class="card-header card-header--flex">
        <span><?= __('members') ?> <span class="text-sm text-dim">(<?= count($members) ?>)</span></span>
        <?php if (count(array_filter($members, fn($m) => empty($m['is_active']))) > 0): ?>
            <label class="form-toggle form-toggle--labeled">
                <input type="checkbox" id="showInactiveMembers" class="form-toggle__input" data-on-change="@toggleRows" data-target=".member-row--inactive">
                <span class="form-toggle__track"></span>
                <span><?= __('show_inactive') ?></span>
            </label>
            <script nonce="<?= csp_nonce() ?>">document.querySelectorAll('.member-row--inactive').forEach(el => el.style.display = 'none');</script>
        <?php endif; ?>
    </div>
<?php
if (empty($members)):
    echo '<div class="empty-state">' . __('no_store_found') . '</div>';
else:
    $activeMemberCount = count(array_filter($members, fn($m) => !empty($m['is_active'])));
    echo Table::make()
        ->data($members)
        ->rowAttrs(fn($m) => empty($m['is_active']) ? ['class' => 'member-row--inactive'] : [])
        ->rowUrl(fn($m) => $BASE_URL . '/admin/users/' . employee_segment((int) $m['id']) . '/edit')
        ->column(__('name'), fn($m) => '<strong>' . htmlspecialchars($m['user_name']) . '</strong>')
        ->column(__('email'), fn($m) => '<span class="text-sm td-muted">' . htmlspecialchars($m['user_email']) . '</span>')
        ->column(__('role'), function($m) {
            return Badge::make($m['role_name'] ?? '—')
                ->variant(!empty($m['role_is_managing']) ? 'warning' : 'active')
                ->render();
        })
        ->column(__('status'), fn($m) => !empty($m['is_active']) ? Badge::make(__('active'))->active()->render() : Badge::make(__('inactive'))->inactive()->render())
        ->column(__('actions'), function($m) use ($BASE_URL, $storeId, $store, $assignable_roles, $can) {
            $mid = (int) $m['id'];
            $currentRoleId = (int) ($m['role_id'] ?? 0);
            $html = '<div class="btn-group">';
            if ($can('employees.update')) {
                $html .= '<form method="POST" action="' . htmlspecialchars($BASE_URL . '/admin/stores/' . store_segment($storeId) . '/members/' . $mid . '/role') . '" class="form-inline-flex">' . csrf_field()
                    . '<select name="role_id" class="form-control form-control-sm w-180">';
                foreach ($assignable_roles as $r) {
                    $sel = (int) $r['id'] === $currentRoleId ? ' selected' : '';
                    $html .= '<option value="' . (int) $r['id'] . '"' . $sel . '>' . htmlspecialchars($r['name'] ?? '') . '</option>';
                }
                $html .= '</select>' . Button::make(__('apply'))->ghost()->sm()->submit()->render() . '</form>';
            }
            if ($can('payroll.view')) {
                $html .= '<a href="' . $BASE_URL . '/admin/stores/' . store_segment((int) $store['id']) . '/members/' . $mid . '/deductions" class="btn btn--ghost btn--sm" title="' . __('deduction_overrides') . '">💰</a>';
            }
            if ($can('employees.update')) {
                $html .= '<form method="POST" action="' . $BASE_URL . '/admin/stores/' . store_segment($storeId) . '/members/' . $mid . '/delete" class="form-inline" data-confirm="' . htmlspecialchars(__('confirm_remove_member'), ENT_QUOTES) . '">' . csrf_field()
                    . '<button type="submit" class="btn btn--danger btn--sm">' . __('remove') . '</button></form>';
            }
            $html .= '</div>';
            return $html;
        })
        ->render();
endif;
?>
    <?php if (!empty($available) && $can('employees.update')): ?>
        <div class="card-body--add">
            <form method="POST" action="<?= $BASE_URL ?>/admin/stores/<?= store_segment($storeId) ?>/members" class="form-flex--add">
                <?= csrf_field() ?>
                <div class="form-group form-group--flex1">
                    <label class="form-label"><?= __('user') ?></label>
                    <select name="user_id" class="form-control" required>
                        <option value="">— <?= __('select') ?> —</option>
                        <?php foreach ($available as $u): ?>
                            <?php $uName = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '')) ?: ($u['email'] ?? '#' . $u['id']); ?>
                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($uName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group form-group--fixed">
                    <label class="form-label"><?= __('role') ?></label>
                    <select name="role_id" class="form-control">
                        <?php foreach ($assignable_roles as $r): ?>
                            <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) ($default_role_id ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($r['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= Button::make(__('add'))->primary()->submit()->render() ?>
            </form>
        </div>
    <?php else: ?>
        <div class="card-footer--muted"><?= __('all_users_already_members') ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>
