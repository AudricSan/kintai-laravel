<?php
use kintai\UI\Components\Flash;
use kintai\UI\Components\Badge;

/** @var array $registries Liste de ['id' => int, 'name' => string, 'url' => string, 'is_official' => bool] */
/** @var string|null $BASE_URL */

echo Flash::fromQuery('success', [
    'created' => __('bundle_registry_created_success'),
    'deleted' => __('bundle_registry_deleted_success'),
])->render();

echo Flash::fromQuery('error', [
    'invalid'                    => __('bundle_registry_invalid_error'),
    'duplicate'                  => __('bundle_registry_duplicate_error'),
    'delete_official_forbidden'  => __('bundle_registry_delete_official_forbidden'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('bundle_registries') ?> <span class="page-count">(<?= count($registries) ?>)</span></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>
<?php include __DIR__ . '/_bundle-tabs.php'; ?>

<div class="card card--mb">
    <div class="card-body">
        <p class="form-hint"><?= __('bundle_registries_hint') ?></p>
    </div>
</div>

<div class="card mb-sm">
    <div class="table-wrap">
        <table class="data-table" data-mob-stack>
            <thead>
                <tr>
                    <th><?= __('bundle_registry_name') ?></th>
                    <th><?= __('bundle_registry_url') ?></th>
                    <th class="td-center"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registries as $registry): ?>
                <tr>
                    <td data-label="<?= htmlspecialchars(__('bundle_registry_name')) ?>">
                        <?= htmlspecialchars($registry['name']) ?>
                        <?php if ($registry['is_official']): ?>
                            <?= Badge::make(__('official'))->primary()->sm()->render() ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= htmlspecialchars(__('bundle_registry_url')) ?>"><code><?= htmlspecialchars($registry['url']) ?></code></td>
                    <td data-label="<?= htmlspecialchars(__('actions')) ?>" class="td-center td-nowrap">
                        <?php if (!$registry['is_official']): ?>
                        <form method="POST" action="<?= $BASE_URL ?>/admin/bundles/registries/<?= (int) $registry['id'] ?>/delete" class="form-inline"
                              data-confirm="<?= htmlspecialchars(__('bundle_registry_confirm_delete'), ENT_QUOTES) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--danger btn--xs"><?= __('delete') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h3 class="card-title"><?= __('bundle_registry_add') ?></h3>
        <form method="POST" action="<?= $BASE_URL ?>/admin/bundles/registries" class="form-flex">
            <div class="form-group">
                <label class="form-label"><?= __('bundle_registry_name') ?></label>
                <input type="text" name="name" class="form-control form-control-sm" maxlength="150" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('bundle_registry_url') ?></label>
                <input type="url" name="url" class="form-control form-control-sm" placeholder="https://raw.githubusercontent.com/..." required>
            </div>
            <div class="form-group">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary btn--sm"><?= __('bundle_registry_add') ?></button>
            </div>
        </form>
    </div>
</div>
