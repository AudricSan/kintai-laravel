<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/**
 * @var string   $mode
 * @var string[] $keep
 * @var string   $backup_filename
 * @var string   $download_url
 */

$executeAction = route_url('admin.reset.execute');
$backAction    = route_url('admin.backup');
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('reset_title') ?></h2>
</div>

<div class="alert alert--success mb-sm">
    <?= __('reset_backup_created') ?> <code><?= htmlspecialchars($backup_filename) ?></code>
    — <a href="<?= htmlspecialchars($download_url) ?>" id="reset-download-link"><?= __('reset_download_now') ?></a>
</div>

<?php
ob_start();
?>
<p><?= $mode === 'factory' ? __('reset_factory_summary') : __('reset_data_summary') ?></p>
<?php if ($mode === 'data' && $keep !== []): ?>
    <p class="text-muted"><?= __('reset_keep_summary') ?> : <?= htmlspecialchars(implode(', ', array_map(
        fn($k) => __('reset_keep_' . $k),
        $keep
    ))) ?></p>
<?php endif; ?>

<form method="POST" action="<?= htmlspecialchars($executeAction) ?>"
      data-confirm="<?= htmlspecialchars($mode === 'factory' ? __('reset_factory_confirm') : __('reset_data_confirm'), ENT_QUOTES) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
    <input type="hidden" name="backup_filename" value="<?= htmlspecialchars($backup_filename) ?>">
    <?php foreach ($keep as $k): ?>
        <input type="hidden" name="keep[]" value="<?= htmlspecialchars($k) ?>">
    <?php endforeach; ?>
    <div class="form-actions">
        <?= Button::make(__('reset_confirm_btn'))->danger()->submit()->render() ?>
        <a href="<?= htmlspecialchars($backAction) ?>" class="btn btn--ghost"><?= __('cancel') ?></a>
    </div>
</form>
<?php echo Card::make()->header(__('reset_confirm_card_title'))->body(ob_get_clean())->render(); ?>

<script nonce="<?= csp_nonce() ?>">document.getElementById('reset-download-link').click();</script>
