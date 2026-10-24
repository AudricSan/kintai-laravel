<?php

use kintai\UI\Components\Alert;
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * @var bool   $enabled      liaison Google activée par l'Owner
 * @var string $client_id
 * @var bool   $has_secret   un secret client est enregistré (jamais affiché)
 * @var bool   $available    activée ET identifiants complets
 * @var string $redirect_uri adresse à déclarer dans la console Google Cloud
 * @var int    $connections  utilisateurs actuellement liés
 * @var string $check        résultat du dernier test : ok | invalid_client | unreachable | incomplete | ''
 */

echo Flash::fromQuery('success', ['saved' => __('save_success')])->render();
$checkAlerts = [
    'ok'             => fn() => Alert::make(__('gcal_admin_check_ok'))->success(),
    'invalid_client' => fn() => Alert::make(__('gcal_admin_check_invalid'))->danger(),
    'unreachable'    => fn() => Alert::make(__('gcal_admin_check_unreachable'))->warning(),
    'incomplete'     => fn() => Alert::make(__('gcal_admin_check_incomplete'))->warning(),
];
if (isset($checkAlerts[$check])) {
    echo $checkAlerts[$check]()->render();
}
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('gcal_admin_title') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>

<?php
ob_start();
?>
<p class="text-muted mb-sm"><?= __('gcal_admin_intro') ?></p>
<form method="POST" action="<?= route_url('admin.calendar_settings.save') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-check">
            <input type="checkbox" name="google_calendar_enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
            <span><?= __('gcal_admin_enable') ?></span>
        </label>
        <p class="form-hint"><?= __('gcal_admin_enable_hint') ?></p>
    </div>
    <div class="form-group">
        <label class="form-label" for="google_calendar_client_id"><?= __('gcal_admin_client_id') ?></label>
        <input type="text" id="google_calendar_client_id" name="google_calendar_client_id" class="form-control"
               maxlength="255" spellcheck="false" value="<?= htmlspecialchars($client_id, ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="google_calendar_client_secret"><?= __('gcal_admin_client_secret') ?></label>
        <input type="password" id="google_calendar_client_secret" name="google_calendar_client_secret" class="form-control"
               maxlength="255" autocomplete="new-password" spellcheck="false"
               placeholder="<?= $has_secret ? htmlspecialchars(__('gcal_admin_secret_kept'), ENT_QUOTES) : '' ?>">
        <p class="form-hint"><?= __('gcal_admin_secret_hint') ?></p>
    </div>
    <div class="form-group">
        <label class="form-label" for="google_calendar_redirect"><?= __('gcal_admin_redirect_uri') ?></label>
        <div class="form-inline-flex">
            <input type="text" id="google_calendar_redirect" class="form-control" readonly
                   value="<?= htmlspecialchars($redirect_uri, ENT_QUOTES) ?>" data-on-click="@select">
            <button type="button" class="btn btn--ghost btn--sm"
                    data-copy-url="<?= htmlspecialchars($redirect_uri, ENT_QUOTES) ?>" data-on-click="@copy">
                <?= __('ical_copy') ?>
            </button>
        </div>
        <p class="form-hint"><?= __('gcal_admin_redirect_hint') ?></p>
    </div>
    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
    </div>
</form>
<?php
echo Card::make()->header(__('gcal_admin_card_credentials'))->body(ob_get_clean())->render();

ob_start();
?>
<p>
    <?= ($available ? Badge::make(__('notif_admin_active'))->success() : Badge::make(__('notif_admin_unavailable'))->warning())->render() ?>
    <span class="text-muted"><?= __('gcal_admin_connections', ['count' => $connections]) ?></span>
</p>
<p class="text-muted mb-sm"><?= __('gcal_admin_cron_hint') ?></p>
<p class="text-muted mb-sm"><?= __('gcal_admin_test_mode_warning') ?></p>
<form method="POST" action="<?= route_url('admin.calendar_settings.test') ?>">
    <?= csrf_field() ?>
    <?= Button::make(__('gcal_admin_test_button'))->ghost()->submit()->render() ?>
</form>
<?php
echo Card::make()->header(__('gcal_admin_card_state'))->body(ob_get_clean())->render();
