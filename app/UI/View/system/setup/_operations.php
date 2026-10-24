<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/** @var array<string, mixed> $stepData */
$action = route_url('admin.setup.step', ['step' => 'operations']);
ob_start();
?>
<p class="mb-sm"><?= __('setup_ops_intro') ?></p>
<form method="POST" action="<?= htmlspecialchars($action) ?>" class="form-stack">
    <?= csrf_field() ?>
    <label class="form-toggle form-toggle--labeled">
        <input type="checkbox" name="backup_auto" value="1"<?= $stepData['backup_auto'] ? ' checked' : '' ?>>
        <span><?= __('setup_ops_backup_auto') ?></span>
    </label>
    <div class="form-group">
        <label class="form-label" for="setup-ops-keep"><?= __('setup_ops_backup_keep') ?></label>
        <input type="number" id="setup-ops-keep" name="backup_keep" class="form-control" min="0" max="365" value="<?= (int) $stepData['backup_keep'] ?>">
        <p class="form-hint"><?= __('setup_ops_backup_keep_hint') ?></p>
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-ops-channel"><?= __('setup_ops_channel') ?></label>
        <select id="setup-ops-channel" name="update_channel" class="form-control">
            <?php foreach ($stepData['channels'] as $_ch): ?>
                <option value="<?= htmlspecialchars($_ch) ?>"<?= $_ch === $stepData['update_channel'] ? ' selected' : '' ?>><?= __('setup_ops_channel_' . $_ch) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="form-hint"><?= __('setup_ops_channel_hint') ?></p>
    </div>
    <div class="form-actions">
        <?= Button::make(__('setup_save_next'))->primary()->submit()->render() ?>
    </div>
</form>

<h4 class="setup-cron__title"><?= __('setup_ops_cron_title') ?></h4>
<p class="text-sm"><?= __('setup_ops_cron_intro') ?></p>
<p class="text-sm"><strong><?= __('setup_ops_cron_command') ?></strong></p>
<pre class="setup-cron__code"><code><?= htmlspecialchars((string) $stepData['cron_command']) ?></code></pre>
<p class="text-sm"><strong><?= __('setup_ops_cron_url') ?></strong></p>
<pre class="setup-cron__code"><code><?= htmlspecialchars((string) $stepData['cron_url']) ?>?token=…</code></pre>
<p class="text-sm text-muted"><?= __('setup_ops_cron_token') ?></p>
<pre class="setup-cron__code"><code><?= htmlspecialchars((string) $stepData['cron_token_command']) ?></code></pre>
<?php include __DIR__ . '/_skip.php'; ?>
<?php echo Card::make()->header(__('setup_step_operations'))->body(ob_get_clean())->render(); ?>
