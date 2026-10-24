<?php
use kintai\UI\Components\Alert;
use kintai\UI\Components\Button;

/** @var bool   $error */
/** @var string $login_mode  'code' | 'email' */
$mode = ($login_mode ?? 'code') === 'email' ? 'email' : 'code';
?>

<?php if ($error ?? false):
    echo Alert::make(__('invalid_credentials'))->danger()->render();
endif; ?>
<?php if ($expired ?? false):
    echo Alert::make(__('session_expired_login'))->warning()->render();
endif; ?>

<!-- Onglets -->
<div class="login-tabs">
    <button type="button" class="login-tab <?= $mode === 'code'  ? 'active' : '' ?>"
            data-tab="code">
        <?= __('login_code') ?>
    </button>
    <button type="button" class="login-tab <?= $mode === 'email' ? 'active' : '' ?>"
            data-tab="email">
        <?= __('login_email') ?>
    </button>
</div>

<form method="POST" action="<?= route_url('auth.login') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="login_mode" id="login_mode_input" value="<?= htmlspecialchars($mode, ENT_QUOTES) ?>">
    <div class="form-stack">

        <!-- ── Mode employé ──────────────────────────────────────────── -->
        <div class="login-panel <?= $mode === 'code' ? 'login-panel--active' : '' ?>"
             id="panel-code">
            <div class="form-group">
                <label class="form-label form-label--required"><?= __('employee_code') ?></label>
                <input type="text" name="employee_code" class="form-control input-code"
                       placeholder="ex : EMP001"
                       autocomplete="username" required
                       <?= $mode !== 'code' ? 'disabled' : 'autofocus' ?>>
            </div>

            <div class="form-group">
                <label class="form-label form-label--required"><?= __('store_code') ?></label>
                <input type="text" name="store_code" class="form-control input-code"
                       placeholder="ex : HQ"
                       autocomplete="organization" required
                       <?= $mode !== 'code' ? 'disabled' : '' ?>>
            </div>

            <div class="form-group">
                <label class="form-label form-label--required"><?= __('password') ?></label>
                <input type="password" name="password" class="form-control"
                       autocomplete="current-password" placeholder="0000" required
                       <?= $mode !== 'code' ? 'disabled' : '' ?>>
                <?php // Plus d'indice du mot de passe par défaut sur cette page publique (audit n°8) : les gérants le communiquent. ?>
            </div>
        </div>

        <!-- ── Mode admin / manager ──────────────────────────────────── -->
        <div class="login-panel <?= $mode === 'email' ? 'login-panel--active' : '' ?>"
             id="panel-email">
            <div class="form-group">
                <label class="form-label form-label--required"><?= __('email_address') ?></label>
                <input type="email" name="email" class="form-control"
                       placeholder="vous@exemple.com"
                       autocomplete="email" required
                       <?= $mode !== 'email' ? 'disabled' : 'autofocus' ?>>
            </div>

            <div class="form-group">
                <label class="form-label form-label--required"><?= __('password') ?></label>
                <input type="password" name="password" class="form-control"
                       autocomplete="current-password" required
                       <?= $mode !== 'email' ? 'disabled' : '' ?>>
                <p class="login-hint login-hint--end">
                    <a href="<?= route_url('password.forgot') ?>"><?= __('forgot_password_link') ?></a>
                </p>
            </div>
        </div>

        <label class="form-check">
            <input type="checkbox" name="remember" value="1">
            <?= __('remember_me_30_days') ?>
        </label>

        <?= Button::make(__('connect'))->primary()->full()->submit()->render() ?>

    </div>
</form>

<?php if (count($login_languages ?? []) > 1): ?>
<nav class="login-lang" aria-label="<?= htmlspecialchars(__('language')) ?>">
    <?php foreach ($login_languages as $_lang): ?>
        <form method="POST" action="<?= route_url('lang.switch', ['locale' => $_lang['code']]) ?>">
            <?= csrf_field() ?>
            <button type="submit" lang="<?= htmlspecialchars($_lang['code']) ?>"
                    class="login-lang__link<?= ($current_locale ?? '') === $_lang['code'] ? ' login-lang__link--active' : '' ?>"
                    <?= ($current_locale ?? '') === $_lang['code'] ? 'aria-current="true"' : '' ?>><?= htmlspecialchars($_lang['name']) ?></button>
        </form>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var tabs   = document.querySelectorAll('.login-tab[data-tab]');
    var panels = { code: document.getElementById('panel-code'), email: document.getElementById('panel-email') };
    var modeInput = document.getElementById('login_mode_input');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.dataset.tab;

            tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === target); });

            Object.keys(panels).forEach(function (key) {
                var panel  = panels[key];
                var active = key === target;
                panel.classList.toggle('login-panel--active', active);
                panel.querySelectorAll('input').forEach(function (inp) { inp.disabled = !active; });
            });

            modeInput.value = target;

            var first = panels[target].querySelector('input:not([type=hidden])');
            if (first) first.focus();
        });
    });
}());
</script>
