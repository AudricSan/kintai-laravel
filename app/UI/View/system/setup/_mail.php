<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/**
 * @var array<string, mixed>      $stepData
 * @var array<string, mixed>|null $mail_test résultat de l'essai (success, error)
 */
$action = route_url('admin.setup.step', ['step' => 'mail']);
$mail_test ??= null;
ob_start();
?>
<p class="mb-sm"><?= __('setup_mail_intro') ?></p>
<?php if (!empty($mail_test)): ?>
    <?php if ($mail_test['success']): ?>
        <div class="alert alert--success mb-sm"><?= __('setup_mail_test_ok') ?></div>
    <?php else: ?>
        <div class="alert alert--danger mb-sm"><?= htmlspecialchars(__((string) $mail_test['error'])) ?></div>
    <?php endif; ?>
<?php endif; ?>
<form method="POST" action="<?= htmlspecialchars($action) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label" for="setup-mail-driver"><?= __('setup_mail_driver') ?></label>
        <select id="setup-mail-driver" name="driver" class="form-control">
            <option value="native"<?= $stepData['driver'] === 'native' ? ' selected' : '' ?>><?= __('setup_mail_driver_native') ?></option>
            <option value="smtp"<?= $stepData['driver'] === 'smtp' ? ' selected' : '' ?>><?= __('setup_mail_driver_smtp') ?></option>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-mail-from"><?= __('setup_mail_from') ?></label>
        <input type="email" id="setup-mail-from" name="from" class="form-control" required value="<?= htmlspecialchars((string) $stepData['from']) ?>" placeholder="noreply@votre-domaine.com">
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-mail-from-name"><?= __('setup_mail_from_name') ?></label>
        <input type="text" id="setup-mail-from-name" name="from_name" class="form-control" maxlength="100" value="<?= htmlspecialchars((string) $stepData['from_name']) ?>">
    </div>
    <fieldset class="form-group">
        <legend class="form-label"><?= __('setup_mail_smtp') ?></legend>
        <div class="form-group">
            <label class="form-label" for="setup-mail-host"><?= __('setup_mail_host') ?></label>
            <input type="text" id="setup-mail-host" name="host" class="form-control" value="<?= htmlspecialchars((string) $stepData['host']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="setup-mail-port"><?= __('setup_mail_port') ?></label>
            <input type="number" id="setup-mail-port" name="port" class="form-control" min="1" max="65535" value="<?= htmlspecialchars((string) $stepData['port']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="setup-mail-encryption"><?= __('setup_mail_encryption') ?></label>
            <select id="setup-mail-encryption" name="encryption" class="form-control">
                <?php foreach ($stepData['encryptions'] as $_enc): ?>
                    <option value="<?= htmlspecialchars($_enc) ?>"<?= $_enc === $stepData['encryption'] ? ' selected' : '' ?>><?= htmlspecialchars(strtoupper($_enc)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="setup-mail-username"><?= __('setup_mail_username') ?></label>
            <input type="text" id="setup-mail-username" name="username" class="form-control" autocomplete="off" value="<?= htmlspecialchars((string) $stepData['username']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="setup-mail-password"><?= __('setup_mail_password') ?></label>
            <input type="password" id="setup-mail-password" name="password" class="form-control" autocomplete="new-password"
                   placeholder="<?= $stepData['has_password'] ? '••••••••' : '' ?>">
            <p class="form-hint"><?= __('setup_mail_password_hint') ?></p>
        </div>
    </fieldset>
    <div class="form-group">
        <label class="form-label" for="setup-mail-test-to"><?= __('setup_mail_test_to') ?></label>
        <input type="email" id="setup-mail-test-to" name="test_to" class="form-control">
    </div>
    <div class="form-actions">
        <?= Button::make(__('setup_save_next'))->primary()->submit()->render() ?>
        <button type="submit" name="action" value="test" class="btn btn--ghost"><?= __('setup_mail_test_send') ?></button>
    </div>
</form>
<?php include __DIR__ . '/_skip.php'; ?>
<?php echo Card::make()->header(__('setup_step_mail'))->body(ob_get_clean())->render(); ?>
