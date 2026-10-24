<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/**
 * @var array       $mailConfig
 * @var array       $phpIni
 * @var array|null  $result
 * @var string      $last_to
 * @var string      $BASE_URL
 */

$driver      = $mailConfig['driver'] ?? 'native';
$from        = ($mailConfig['from']['address'] ?? '') . ' (' . ($mailConfig['from']['name'] ?? '') . ')';
$smtp        = $mailConfig['smtp'] ?? [];
$smtpHost    = ($smtp['host'] ?? '') . ':' . ($smtp['port'] ?? '');
$smtpUser    = $smtp['username'] ?? '';
$smtpEnc     = $smtp['encryption'] ?? '';
$hasPh       = fn(string $v): bool => str_contains($v, 'A_CHANGER') || str_contains($v, 'example.com');
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('mailtest_title') ?></h2>
    <div class="page-header__actions">
        <a href="<?= route_url('home') ?>" class="btn btn--ghost btn--sm">← <?= __('back') ?></a>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="alert alert--<?= $result['success'] ? 'success' : 'danger' ?> mb-sm">
        <?php if ($result['success']): ?>
            <?= __('mailtest_success', ['to' => htmlspecialchars($last_to)]) ?>
        <?php else: ?>
            <strong><?= __('mailtest_failure_title') ?></strong><br>
            <code><?= htmlspecialchars($result['error'] ?? __('mailtest_unknown_error')) ?></code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
ob_start();
?>
<table class="table table--compact mw-600">
    <tbody>
        <tr><th class="w-180"><?= __('mailtest_driver') ?></th><td><code><?= htmlspecialchars($driver) ?></code> <?= $driver === 'native' ? Badge::make(__('mailtest_driver_native_badge'))->warning()->render() : Badge::make(__('mailtest_driver_smtp_badge'))->info()->render() ?></td></tr>
        <tr><th><?= __('mailtest_from') ?></th><td><code><?= htmlspecialchars($from) ?></code> <?= $hasPh($mailConfig['from']['address'] ?? '') ? Badge::make(__('mailtest_placeholder_badge'))->danger()->render() : '' ?></td></tr>
        <?php if ($driver === 'smtp'): ?>
        <tr><th><?= __('mailtest_smtp_server') ?></th><td><code><?= htmlspecialchars($smtpHost) ?></code> <?= $hasPh($smtp['host'] ?? '') ? Badge::make(__('mailtest_placeholder_badge'))->danger()->render() : '' ?></td></tr>
        <tr><th><?= __('mailtest_username') ?></th><td><code><?= htmlspecialchars($smtpUser) ?></code> <?= $hasPh($smtpUser) ? Badge::make(__('mailtest_placeholder_badge'))->danger()->render() : '' ?></td></tr>
        <tr><th><?= __('mailtest_password') ?></th><td><?php
            if (($smtp['password'] ?? '') === '') echo '<span class="text-muted">' . __('mailtest_password_empty') . '</span>';
            elseif ($hasPh($smtp['password'] ?? '')) echo '<code>' . htmlspecialchars($smtp['password'] ?? '') . '</code> ' . Badge::make(__('mailtest_placeholder_badge'))->danger()->render();
            else echo '<code>••••••••</code> <span class="text-muted">' . __('mailtest_password_set') . '</span>';
        ?></td></tr>
        <tr><th><?= __('mailtest_encryption') ?></th><td><code><?= htmlspecialchars($smtpEnc) ?></code></td></tr>
        <?php else: ?>
        <tr><th><?= __('mailtest_php_ini_smtp') ?></th><td><code><?= htmlspecialchars($phpIni['SMTP'] ?? '') ?></code></td></tr>
        <tr><th><?= __('mailtest_php_ini_smtp_port') ?></th><td><code><?= htmlspecialchars($phpIni['smtp_port'] ?? '') ?></code></td></tr>
        <tr><th><?= __('mailtest_sendmail_path') ?></th><td><code><?= htmlspecialchars($phpIni['sendmail_path'] ?? '') ?></code></td></tr>
        <?php endif; ?>
    </tbody>
</table>
<?php if ($driver === 'native'): ?>
    <div class="alert alert--warning mt-sm">
        <?= __('mailtest_driver_native_warning') ?>
    </div>
<?php endif; ?>
<?php echo Card::make()->header(__('mailtest_current_config_card'))->body(ob_get_clean())->render(); ?>

<?php echo Card::make()
    ->header(__('mailtest_htaccess_card'))
    ->body('<p class="mb-sm text-muted">' . __('mailtest_htaccess_intro') . '</p>
        <pre class="code-block">SetEnv MAIL_DRIVER      smtp
SetEnv MAIL_FROM_ADDRESS noreply@votredomaine.com
SetEnv MAIL_FROM_NAME   Kintai
SetEnv MAIL_HOST        pro1.mail.ovh.net
SetEnv MAIL_PORT        465
SetEnv MAIL_USERNAME    noreply@votredomaine.com
SetEnv MAIL_PASSWORD    votre_mot_de_passe_smtp
SetEnv MAIL_ENCRYPTION  ssl</pre>
        <p class="form-hint mt-sm">' . __('mailtest_ovh_gmail_hint') . '</p>')
    ->render() ?>

<?php
ob_start();
?>
<form method="POST" action="<?= htmlspecialchars(route_url('admin.mail_test')) ?>">
    <?= csrf_field() ?>
    <div class="form-group mw-420">
        <label class="form-label" for="mail_test_to"><?= __('mailtest_recipient_label') ?></label>
        <input type="email" id="mail_test_to" name="to" class="form-control"
               placeholder="votre@email.com" value="<?= htmlspecialchars($last_to) ?>" required>
        <span class="form-hint"><?= __('mailtest_recipient_hint') ?></span>
    </div>
    <div class="form-actions">
        <?= Button::make(__('mailtest_send_btn'))->primary()->submit()->render() ?>
    </div>
</form>
<?php echo Card::make()->header(__('mailtest_send_card'))->body(ob_get_clean())->render(); ?>
