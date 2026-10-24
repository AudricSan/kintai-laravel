<?php
use kintai\UI\Components\Button;

/** Bouton « Passer cette étape » commun aux étapes de formulaire (formulaire séparé pour ne rien enregistrer). */
if (!empty($skippable) && isset($screen)):
    $_skipAction = in_array($screen, ['bundles', 'license'], true) ? route_url('admin.setup.' . $screen) : route_url('admin.setup.step', ['step' => $screen]);
?>
<form method="POST" action="<?= htmlspecialchars($_skipAction) ?>" class="mt-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="skip" value="1">
    <?= Button::make(__('setup_skip_step'))->ghost()->submit()->render() ?>
</form>
<?php endif; ?>
