<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * @var bool        $configured
 * @var string|null $licenseKey
 * @var string      $instanceId
 * @var array|null  $state
 * @var array|null  $entitlements
 * @var bool        $isPaidActive
 * @var int|null    $maxStores
 * @var int         $currentStores
 * @var int|null    $maxEmployees
 * @var int         $currentEmployees
 * @var int|null    $maxBundles
 * @var int         $currentBundles
 * @var string|null $BASE_URL
 */

echo Flash::fromQuery('success', [
    'activated'   => __('license_activated_success'),
    'refreshed'   => __('license_refreshed_success'),
    'deactivated' => __('license_deactivated_success'),
])->render();
echo Flash::fromQuery('error', [
    'missing_license_key'   => __('license_missing_key'),
    'server_not_configured' => __('license_server_not_configured'),
    'server_unreachable'    => __('license_server_unreachable'),
    'no_license'            => __('license_none_registered'),
    // Erreur renvoyée par le serveur de licences : le texte exact est affiché plus bas (« dernière erreur »),
    // depuis l'état enregistré, jamais depuis l'URL.
    'default'               => __('error_generic'),
])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('license') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>

<?php
ob_start();
?>
<p class="mb-sm">
    <?= __('license_current_plan') ?>
    <?= $isPaidActive ? Badge::make(__('license_plan_paid'))->success()->render() : Badge::make(__('license_plan_free'))->neutral()->render() ?>
    <?php if (($state['status'] ?? null) === 'degraded'): ?>
        <?= Badge::make(__('license_status_degraded'))->warning()->render() ?>
    <?php endif; ?>
</p>
<?php if (!$isPaidActive): ?>
    <p class="text-muted text-sm">
        <?= __('license_free_plan_limits') ?>
    </p>
<?php endif; ?>
<?php if ($state !== null): ?>
    <ul class="text-sm">
        <?php if (!empty($state['type'])): ?>
            <li><?= __('license_type') ?> : <?= htmlspecialchars((string) $state['type']) ?></li>
        <?php endif; ?>
        <?php if (!empty($entitlements['plan_tier'])): ?>
            <li><?= __('license_plan_tier') ?> : <?= htmlspecialchars((string) $entitlements['plan_tier']) ?></li>
        <?php endif; ?>
        <?php
        $issuedAtTs = !empty($state['issued_at']) ? strtotime((string) $state['issued_at']) : false;
        $expiresAtTs = !empty($state['expires_at']) ? strtotime((string) $state['expires_at']) : false;
        ?>
        <?php if ($issuedAtTs !== false): ?>
            <li><?= __('license_issued_at') ?> : <?= htmlspecialchars(date('d/m/Y', $issuedAtTs)) ?></li>
        <?php endif; ?>
        <?php if ($expiresAtTs !== false): ?>
            <li><?= __('license_expires_at') ?> : <?= htmlspecialchars(date('d/m/Y', $expiresAtTs)) ?></li>
        <?php endif; ?>
        <?php if ($issuedAtTs !== false): ?>
            <li>
                <?= __('license_duration') ?> :
                <?php if ($issuedAtTs !== false && $expiresAtTs !== false): ?>
                    <?= __('license_duration_days', ['n' => (int) round(($expiresAtTs - $issuedAtTs) / 86400)]) ?>
                <?php else: ?>
                    <?= __('license_duration_unlimited') ?>
                <?php endif; ?>
            </li>
        <?php endif; ?>
        <?php if (!empty($state['max_activations'])): ?>
            <li><?= __('license_seats') ?> : <?= __('license_seats_value', ['active' => (int) ($state['active_activations'] ?? 0), 'max' => (int) $state['max_activations']]) ?></li>
        <?php endif; ?>
        <?php if (!empty($state['checked_at'])): ?>
            <li><?= __('license_last_checked') ?> : <?= htmlspecialchars(date('d/m/Y H:i', (int) $state['checked_at'])) ?></li>
        <?php endif; ?>
        <?php if (!$isPaidActive && !empty($state['error'])): ?>
            <li class="text-danger"><?= __('license_last_error') ?> : <?= htmlspecialchars((string) $state['error']) ?></li>
        <?php endif; ?>
    </ul>
<?php endif; ?>
<p class="text-muted text-sm">
    <?= __('license_instance_id') ?> : <code><?= htmlspecialchars($instanceId) ?></code>
</p>
<?php if (!$configured): ?>
    <div class="alert alert--info mt-sm"><?= __('license_server_not_configured_hint') ?></div>
<?php endif; ?>
<?php
$statusCard = Card::make()->header(__('license_status_title'))->body(ob_get_clean())->render();
?>

<?php
ob_start();
?>
<ul class="text-sm">
    <li>
        <?= __('license_limit_stores_label') ?> :
        <?= $maxStores !== null
            ? __('license_limit_count_of_max', ['count' => $currentStores, 'max' => $maxStores])
            : __('license_limit_unlimited_count', ['count' => $currentStores]) ?>
    </li>
    <li>
        <?= __('license_limit_employees_label') ?> :
        <?= $maxEmployees !== null
            ? __('license_limit_count_of_max', ['count' => $currentEmployees, 'max' => $maxEmployees])
            : __('license_limit_unlimited_count', ['count' => $currentEmployees]) ?>
    </li>
    <li>
        <?= __('license_limit_bundles_label') ?> :
        <?= $maxBundles !== null
            ? __('license_limit_count_of_max', ['count' => $currentBundles, 'max' => $maxBundles])
            : __('license_limit_unlimited_count', ['count' => $currentBundles]) ?>
    </li>
</ul>
<?php
$limitsCard = Card::make()->header(__('license_limits_title'))->body(ob_get_clean())->render();
?>

<?php
ob_start();
?>
<form method="POST" action="<?= route_url('admin.license.activate') ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="form-group">
        <label class="form-label"><?= __('license_key_label') ?></label>
        <input type="text" name="license_key" class="form-control"
               placeholder="<?= __('license_key_placeholder') ?>"
               value="<?= htmlspecialchars($licenseKey ?? '', ENT_QUOTES) ?>">
        <p class="form-hint"><?= __('license_key_hint') ?></p>
    </div>
    <div class="form-actions">
        <?= Button::make(__('license_activate_btn'))->primary()->submit()->disabled(!$configured)->render() ?>
    </div>
</form>
<?php if ($licenseKey !== null): ?>
    <div class="btn-group mt-sm">
        <form method="POST" action="<?= route_url('admin.license.refresh') ?>" class="d-inline">
            <?= csrf_field() ?>
            <?= Button::make(__('license_refresh_btn'))->sm()->outline()->submit()->disabled(!$configured)->render() ?>
        </form>
        <form method="POST" action="<?= route_url('admin.license.deactivate') ?>" class="d-inline" data-confirm="<?= htmlspecialchars(__('license_deactivate_confirm'), ENT_QUOTES) ?>">
            <?= csrf_field() ?>
            <?= Button::make(__('license_deactivate_btn'))->sm()->danger()->submit()->render() ?>
        </form>
    </div>
<?php endif; ?>
<?php
$activateCard = Card::make()->header(__('license_activate_title'))->body(ob_get_clean())->attrs(['class' => 'settings-grid__full'])->render();
?>

<div class="settings-grid">
    <?= $statusCard ?>
    <?= $limitsCard ?>
    <?= $activateCard ?>
</div>
