<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Flash;

/** @var array $roles             Liste des rôles */
/** @var array $assignment_counts Map role_id => nombre d'affectations */

echo Flash::fromQuery('success', [
    'created' => __('save_success'),
    'updated' => __('save_success'),
    'deleted' => __('save_success'),
])->render();
echo Flash::fromQuery('error', [
    'role_in_use' => __('role_in_use'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('roles') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>

<div class="page-header page-header--end">
    <a href="<?= route_url('admin.roles.create') ?>" class="btn btn--primary"><?= __('new_role') ?></a>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table" data-mob-stack>
            <thead>
                <tr>
                    <th><?= __('name') ?></th>
                    <th><?= __('description') ?></th>
                    <th><?= __('role_holders') ?></th>
                    <th class="td-center"><?= __('edit') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                <?php $rid = (int) $role['id']; ?>
                <tr class="tr--clickable" data-href="<?= htmlspecialchars(route_url('admin.roles.edit', ['id' => $rid])) ?>">
                    <td data-label="<?= htmlspecialchars(__('name')) ?>">
                        <?php if (!empty($role['color'])): ?>
                            <span class="color-swatch <?= style_class(['background' => $role['color']]) ?>"></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($role['name'] ?? '') ?>
                        <?php if (!empty($role['is_system'])): ?>
                            <?= Badge::make(__('system_role'))->render() ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= htmlspecialchars(__('description')) ?>" class="td-muted">
                        <?= htmlspecialchars($role['description'] ?? '') ?>
                    </td>
                    <td data-label="<?= htmlspecialchars(__('role_holders')) ?>">
                        <?= (int) ($assignment_counts[$rid] ?? 0) ?>
                    </td>
                    <td data-label="<?= htmlspecialchars(__('edit')) ?>" class="td-center td-nowrap">
                        <a href="<?= route_url('admin.roles.edit', ['id' => $rid]) ?>" class="btn btn--ghost btn--xs"><?= __('edit') ?></a>
                        <?php if (empty($role['is_system'])): ?>
                        <form method="POST" action="<?= route_url('admin.roles.delete', ['id' => $rid]) ?>"
                              class="d-inline" data-confirm="<?= htmlspecialchars(__('role_delete_confirm'), ENT_QUOTES) ?>">
                            <?= csrf_field() ?>
                            <?= Button::make(__('delete'))->danger()->xs()->submit()->render() ?>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
