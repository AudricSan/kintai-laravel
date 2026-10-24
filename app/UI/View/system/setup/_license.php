<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/**
 * @var bool|null   $licenseConfigured
 * @var string|null $licenseError
 */
ob_start();
?>
    <p class="mb-sm"><?= __('setup_license_intro') ?></p>
    <?php if (empty($licenseConfigured)): ?>
        <div class="alert alert--info mb-sm"><?= __('license_server_not_configured_hint') ?></div>
    <?php endif; ?>
    <form method="POST" action="<?= route_url('admin.setup.license') ?>" class="form-stack">
        <?= csrf_field() ?>
        <div class="form-group">
            <label class="form-label"><?= __('license_key_label') ?></label>
            <input type="text" name="license_key" class="form-control" placeholder="<?= __('license_key_placeholder') ?>">
        </div>
        <div class="form-actions">
            <?= Button::make(__('license_activate_btn'))->primary()->submit()->disabled(empty($licenseConfigured))->render() ?>
        </div>
    </form>
    <form method="POST" action="<?= route_url('admin.setup.license') ?>" class="mt-sm">
        <?= csrf_field() ?>
        <input type="hidden" name="skip" value="1">
        <?= Button::make(__('setup_license_skip'))->ghost()->submit()->render() ?>
    </form>
<?php echo Card::make()->header(__('setup_license_title'))->body(ob_get_clean())->render(); ?>
