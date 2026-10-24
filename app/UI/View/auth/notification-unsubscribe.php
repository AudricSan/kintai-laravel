<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Button;

/** @var string $state      'confirm' | 'done' | 'invalid' */
/** @var string $type_label Libellé du type de notification */
/** @var string $action     Adresse signée du désabonnement (POST) */
?>

<?php if ($state === 'invalid'): ?>

    <?= Alert::make(__('notif_unsub_invalid'))->danger()->render() ?>
    <p class="login-hint login-hint--center">
        <?= Button::make(__('connect'))->primary()->link(route_url('auth.login'))->render() ?>
    </p>

<?php elseif ($state === 'done'): ?>

    <?= Alert::make(__('notif_unsub_done', ['type' => $type_label]))->success()->render() ?>
    <p class="login-hint login-hint--center"><?= __('notif_unsub_done_hint') ?></p>
    <p class="login-hint login-hint--center">
        <?= Button::make(__('connect'))->primary()->link(route_url('auth.login'))->render() ?>
    </p>

<?php else: ?>

    <h2 class="guest-subtitle"><?= __('notif_unsub_title') ?></h2>
    <p class="login-hint"><?= __('notif_unsub_intro', ['type' => htmlspecialchars($type_label, ENT_QUOTES)]) ?></p>

    <form method="POST" action="<?= htmlspecialchars($action, ENT_QUOTES) ?>">
        <div class="form-stack">
            <?= Button::make(__('notif_unsub_button'))->primary()->full()->submit()->render() ?>
        </div>
    </form>

<?php endif; ?>
