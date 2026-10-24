<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Button;

/** @var bool        $valid   Le token est valide et non expiré */
/** @var bool        $success Le mot de passe a été réinitialisé avec succès */
/** @var string|null $error   Message d'erreur à afficher */
/** @var string      $token   Le token dans l'URL */
/** @var string      $BASE_URL */
/** @var string|null $login_url */
?>

<?php if ($success ?? false): ?>

    <?= Alert::make(__('reset_password_success'))->success()->render() ?>
    <p class="login-hint login-hint--center">
        <?= Button::make(__('connect'))->primary()->link($login_url ?? route_url('auth.login'))->render() ?>
    </p>

<?php elseif (!($valid ?? false)): ?>

    <?= Alert::make(__('reset_password_invalid_link'))->danger()->render() ?>
    <p class="login-hint login-hint--center">
        <a href="<?= route_url('password.forgot') ?>"><?= __('new_request') ?></a>
    </p>

<?php else: ?>

    <?php if (!empty($error)):
        // Alert échappe déjà le message : un htmlspecialchars() ici afficherait « &#039; » à la place d'une apostrophe.
        echo Alert::make((string) $error)->danger()->render();
    endif; ?>

    <h2 class="guest-subtitle"><?= __('new_password') ?></h2>
    <p class="login-hint"><?= __('reset_password_hint') ?></p>

    <form method="POST"
          action="<?= $BASE_URL ?>/reset-password/<?= htmlspecialchars($token, ENT_QUOTES) ?>">
        <?= csrf_field() ?>
        <div class="form-stack">
            <div class="form-group">
                <label class="form-label form-label--required"><?= __('new_password') ?></label>
                <input type="password" name="password" class="form-control"
                       autocomplete="new-password" minlength="8" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label form-label--required"><?= __('confirm_password') ?></label>
                <input type="password" name="password_confirmation" class="form-control"
                       autocomplete="new-password" minlength="8" required>
            </div>

            <?= Button::make(__('reset_password_submit'))->primary()->full()->submit()->render() ?>
        </div>
    </form>

<?php endif; ?>
