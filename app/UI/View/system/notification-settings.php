<?php

use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * @var int      $retention_days
 * @var int      $retention_min
 * @var int      $retention_max
 * @var array    $status        voir NotificationChannelStatus::all()
 * @var string[] $test_channels canaux du dernier test envoyé
 */

$channelLabels = [
    'bell'     => __('notif_channel_bell'),
    'web_push' => __('notif_channel_web_push'),
    'mobile'   => __('notif_channel_mobile'),
    'email'    => __('notif_channel_email'),
];

$flash = Flash::fromQuery('success', [
    'saved' => __('save_success'),
    'vapid' => __('notif_admin_vapid_done'),
]);
if (($_GET['success'] ?? '') === 'test') {
    echo Alert::make(__('notif_admin_test_sent', ['channels' => implode(', ', array_map(fn($c) => $channelLabels[$c], $test_channels))]))->success()->render();
} else {
    echo $flash->render();
}
if (($_GET['error'] ?? '') === 'vapid_failed') {
    echo Alert::make(__('notif_admin_vapid_failed'))->danger()->render();
}

$badge = fn(bool $ok, string $yes, string $no): string => ($ok ? Badge::make($yes)->success() : Badge::make($no)->warning())->render();
$web   = $status['web_push'];
$mail  = $status['email'];
$fcm   = $status['mobile'];
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('notif_admin_title') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>

<?php
ob_start();
?>
<form method="POST" action="<?= route_url('admin.notifications_settings.save') ?>">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label" for="notifications_retention_days"><?= __('notif_admin_retention') ?></label>
        <input type="number" id="notifications_retention_days" name="notifications_retention_days" class="form-control w-100"
               min="<?= $retention_min ?>" max="<?= $retention_max ?>" value="<?= $retention_days ?>">
        <p class="form-hint"><?= __('notif_admin_retention_hint', ['min' => $retention_min]) ?></p>
    </div>
    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
    </div>
</form>
<?php
echo Card::make()->header(__('notif_admin_card_retention'))->body(ob_get_clean())->render();

ob_start();
?>
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr><th><?= __('notif_admin_channel') ?></th><th><?= __('notif_admin_state') ?></th><th><?= __('notif_admin_detail') ?></th></tr>
        </thead>
        <tbody>
            <tr>
                <td><?= $channelLabels['bell'] ?></td>
                <td><?= Badge::make(__('notif_admin_active'))->success()->render() ?></td>
                <td class="text-muted"><?= __('notif_admin_bell_detail') ?></td>
            </tr>
            <tr>
                <td><?= $channelLabels['web_push'] ?></td>
                <td><?= $badge($web['available'], __('notif_admin_active'), __('notif_admin_unavailable')) ?></td>
                <td class="text-muted">
                    <?php if ($web['missing_extensions'] !== []): ?>
                        <?= __('notif_admin_web_missing_extensions', ['extensions' => implode(', ', $web['missing_extensions'])]) ?>
                    <?php elseif (!$web['has_keys']): ?>
                        <?= __('notif_admin_web_no_keys') ?>
                    <?php else: ?>
                        <?= __('notif_admin_web_subscriptions', ['count' => $web['subscriptions']]) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><?= $channelLabels['email'] ?></td>
                <td><?= $badge($mail['available'], __('notif_admin_active'), __('notif_admin_unavailable')) ?></td>
                <td class="text-muted">
                    <?php if (!$mail['configured']): ?>
                        <?= __('notif_admin_email_not_configured') ?>
                    <?php elseif (!$mail['has_public_url']): ?>
                        <?= __('notif_admin_email_no_url') ?>
                    <?php else: ?>
                        <?= __('notif_admin_email_driver', ['driver' => $mail['driver']]) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><?= $channelLabels['mobile'] ?></td>
                <td><?= $badge($fcm['available'], __('notif_admin_active'), __('notif_admin_unavailable')) ?></td>
                <td class="text-muted">
                    <?php if (!$fcm['enabled']): ?>
                        <?= __('notif_admin_mobile_disabled') ?>
                    <?php elseif (!$fcm['has_project'] || !$fcm['has_credentials']): ?>
                        <?= __('notif_admin_mobile_incomplete') ?>
                    <?php else: ?>
                        <?= __('notif_admin_mobile_ready') ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>
<?php
echo Card::make()->header(__('notif_admin_card_channels'))->body(ob_get_clean())->render();

ob_start();
?>
<p class="text-muted mb-sm"><?= __('notif_admin_test_intro') ?></p>
<form method="POST" action="<?= route_url('admin.notifications_settings.test') ?>">
    <?= csrf_field() ?>
    <?= Button::make(__('notif_admin_test_button'))->primary()->submit()->render() ?>
</form>
<?php
echo Card::make()->header(__('notif_admin_card_test'))->body(ob_get_clean())->render();

ob_start();
?>
<p class="text-muted mb-sm"><?= __('notif_admin_vapid_intro') ?></p>
<form method="POST" action="<?= route_url('admin.notifications_settings.vapid') ?>"
      data-confirm="<?= htmlspecialchars(__('notif_admin_vapid_confirm'), ENT_QUOTES) ?>">
    <?= csrf_field() ?>
    <?= Button::make(__('notif_admin_vapid_button'))->danger()->submit()->render() ?>
</form>
<?php
echo Card::make()->header(__('notif_admin_card_vapid'))->body(ob_get_clean())->render();
