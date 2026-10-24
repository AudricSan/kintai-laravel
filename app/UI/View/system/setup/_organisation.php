<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/** @var array<string, mixed> $stepData */
$action = route_url('admin.setup.step', ['step' => 'organisation']);
ob_start();
?>
<p class="mb-sm"><?= __('setup_org_intro') ?></p>
<form method="POST" action="<?= htmlspecialchars($action) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label" for="setup-org-name"><?= __('setup_org_name') ?></label>
        <input type="text" id="setup-org-name" name="name" class="form-control" maxlength="100" required value="<?= htmlspecialchars((string) $stepData['name']) ?>">
        <p class="form-hint"><?= __('setup_org_name_hint') ?></p>
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-org-email"><?= __('setup_org_support_email') ?></label>
        <input type="email" id="setup-org-email" name="support_email" class="form-control" value="<?= htmlspecialchars((string) $stepData['support_email']) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="setup-org-url"><?= __('setup_org_public_url') ?></label>
        <input type="url" id="setup-org-url" name="public_url" class="form-control" placeholder="<?= htmlspecialchars((string) ($stepData['public_url_suggestion'] ?? '')) ?>"
               value="<?= htmlspecialchars((string) $stepData['public_url']) ?>"<?= !empty($stepData['public_url_forced']) ? ' disabled' : '' ?>>
        <p class="form-hint"><?= !empty($stepData['public_url_forced']) ? __('setup_org_public_url_forced') : __('setup_org_public_url_hint') ?></p>
    </div>
    <div class="form-actions">
        <?= Button::make(__('setup_save_next'))->primary()->submit()->render() ?>
    </div>
</form>
<?php include __DIR__ . '/_skip.php'; ?>
<?php echo Card::make()->header(__('setup_step_organisation'))->body(ob_get_clean())->render(); ?>
