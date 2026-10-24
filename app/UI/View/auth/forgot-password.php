<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Button;

/** @var bool   $sent */
/** @var bool   $error */
/** @var string $BASE_URL */
?>

<?php if ($sent ?? false): ?>

    <?= Alert::make(__('forgot_password_sent'))->success()->render() ?>

    <p class="login-hint login-hint--center">
        <a href="<?= route_url('auth.login') ?>">← <?= __('back_to_login') ?></a>
    </p>

<?php else: ?>

    <?php if ($error ?? false):
        echo Alert::make('Adresse e-mail invalide.')->danger()->render();
    endif; ?>

    <h2 class="guest-subtitle"><?= __('forgot_password_title') ?></h2>
    <p class="login-hint">
        <?= __('forgot_password_intro') ?>
    </p>

    <form method="POST" action="<?= route_url('password.forgot') ?>">
        <?= csrf_field() ?>
        <div class="form-stack">
            <div class="form-group">
                <label class="form-label form-label--required">Adresse e-mail</label>
                <input type="email" name="email" class="form-control"
                       placeholder="vous@exemple.com"
                       autocomplete="email" autofocus required>
            </div>

            <?= Button::make('Envoyer le lien')->primary()->full()->submit()->render() ?>
        </div>
    </form>

    <p class="login-hint login-hint--center">
        <a href="<?= route_url('auth.login') ?>">← <?= __('back_to_login') ?></a>
    </p>

<?php endif; ?>
