<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/** @var array<string, mixed> $stepData */
$status = $stepData['status'];
$action = route_url('admin.setup.step', ['step' => 'notifications']);
$rows = [
    ['setup_notif_bell', true, __('setup_notif_bell_hint')],
    ['setup_notif_web_push', (bool) $status['web_push']['available'], $status['web_push']['available'] ? __('setup_notif_web_push_ok') : __('setup_notif_web_push_ko')],
    ['setup_notif_email', (bool) $status['email']['available'], $status['email']['available'] ? __('setup_notif_email_ok') : __('setup_notif_email_ko')],
    ['setup_notif_mobile', (bool) $status['mobile']['available'], $status['mobile']['available'] ? __('setup_notif_mobile_ok') : __('setup_notif_mobile_ko')],
];
ob_start();
?>
<p class="mb-sm"><?= __('setup_notif_intro') ?></p>
<ul class="setup-channels">
    <?php foreach ($rows as [$label, $ok, $hint]): ?>
        <li class="setup-channels__item<?= $ok ? ' setup-channels__item--ok' : '' ?>">
            <span class="setup-channels__mark" aria-hidden="true"><?= $ok ? '✓' : '–' ?></span>
            <span><strong><?= __($label) ?></strong> <span class="text-muted text-sm">— <?= htmlspecialchars($hint) ?></span></span>
        </li>
    <?php endforeach; ?>
</ul>
<p class="text-sm text-muted"><?= __('setup_notif_more') ?> <a href="<?= route_url('admin.notifications_settings') ?>"><?= __('setup_notif_settings_link') ?></a></p>
<form method="POST" action="<?= htmlspecialchars($action) ?>" class="form-stack">
    <?= csrf_field() ?>
    <label class="form-toggle form-toggle--labeled">
        <input type="checkbox" name="send_test" value="1">
        <span><?= __('setup_notif_send_test') ?></span>
    </label>
    <div class="form-actions">
        <?= Button::make(__('setup_save_next'))->primary()->submit()->render() ?>
    </div>
</form>
<?php include __DIR__ . '/_skip.php'; ?>
<?php echo Card::make()->header(__('setup_step_notifications'))->body(ob_get_clean())->render(); ?>
