<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Flash;

/** @var array $bundles Liste de ['key' => string, 'label' => string, 'desc' => string, 'enabled' => bool, 'official' => bool] */
/** @var bool  $success */
/** @var int|null $maxActiveBundles */
/** @var int  $activeCount */
/** @var string|null $BASE_URL */

$maxActiveBundles ??= null;
$activeCount      ??= 0;

echo Flash::fromQuery('success', ['default' => __('save_success')])->render();
echo Flash::fromQuery('error', [
    'bundle_quota_exceeded' => __('bundle_quota_exceeded', ['max' => $maxActiveBundles]),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('bundle_settings') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>
<?php include __DIR__ . '/_bundle-tabs.php'; ?>

<div class="card card--mb">
    <div class="card-body">
        <p class="form-hint"><?= __('bundle_settings_hint') ?></p>
        <?php if ($maxActiveBundles !== null): ?>
            <p class="form-hint"><?= __('bundle_active_count', ['count' => $activeCount, 'max' => $maxActiveBundles]) ?></p>
        <?php endif; ?>
    </div>
</div>

<form method="POST" action="<?= route_url('admin.bundles') ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-body form-stack">
            <div class="feature-cards">
                <?php if (empty($bundles)): ?>
                    <p class="form-hint"><?= __('bundle_none_found') ?></p>
                <?php endif; ?>
                <?php foreach ($bundles as $b): ?>
                    <label class="feature-card">
                        <input type="checkbox" name="bundle_<?= htmlspecialchars($b['key']) ?>" value="1"
                               <?= $b['enabled'] ? 'checked' : '' ?> class="feature-card__input">
                        <div class="feature-card__body">
                            <span class="feature-card__title">
                                <?= htmlspecialchars($b['label']) ?>
                                <span class="badge badge--neutral badge--sm"><?= htmlspecialchars(__('bundle_version', ['version' => $b['version']])) ?></span>
                                <?php if (!$b['official']): ?>
                                    <span class="badge badge--warning badge--sm"><?= __('bundle_third_party') ?></span>
                                <?php endif; ?>
                                <?php if (!empty($b['incompatible'])): ?>
                                    <span class="badge badge--danger badge--sm"><?= __('bundle_incompatible') ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="feature-card__desc"><?= htmlspecialchars($b['desc']) ?></span>
                            <?php if (!empty($b['incompatible'])): ?>
                                <span class="form-hint text-danger"><?= htmlspecialchars(__('bundle_incompatible_not_loaded', ['reason' => $b['incompatible']])) ?></span>
                            <?php endif; ?>
                            <?php if (!$b['official']): ?>
                                <span class="form-hint"><?= __('bundle_third_party_warning') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="feature-card__toggle">
                            <span class="toggle-switch">
                                <span class="toggle-switch__track"></span>
                                <span class="toggle-switch__thumb"></span>
                            </span>
                            <span class="toggle-switch__label">
                                <?= $b['enabled'] ? __('active') : __('inactive') ?>
                            </span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
    </div>
</form>
