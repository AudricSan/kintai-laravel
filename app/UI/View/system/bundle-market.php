<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;

/**
 * @var array $entries Liste de bundles catalogués, voir BundleMarketController::market().
 * @var string|null $BASE_URL
 * @var string|null $error
 * @var string|null $success
 * @var string|null $uninstalled
 * @var string $bundleUpdateChannel
 * @var string|null $channelSaved
 */

$channelAction = route_url('admin.bundles.market.channel');
$channels = ['release' => __('update_channel_release'), 'beta' => __('update_channel_beta'), 'alpha' => __('update_channel_alpha')];

// Repère visuel par bundle, faute d'icône dédiée par slug — même recette que
// storeChipColor() dans staff/stores.php (couleur dérivée du slug, stable
// d'un chargement à l'autre) et le même composant .avatar-chip que les
// listes employés/magasins, juste en plus grand (--lg).
$bundleChipColor = function (string $seed): string {
    $hue = crc32($seed) % 360;
    return "hsl({$hue}, 62%, 52%)";
};
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('bundle_market') ?> <span class="page-count">(<?= count($entries) ?>)</span></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>
<?php include __DIR__ . '/_bundle-tabs.php'; ?>

<?php // Le slug vient de l'URL : n'afficher le message que pour un slug bien formé, jamais un texte libre. ?>
<?php if ($success && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', (string) $success) === 1): ?>
    <div class="alert alert--success mb-sm"><?= htmlspecialchars(__('bundle_market_install_success', ['slug' => $success])) ?>
        <?php if (($activation ?? null) === 'activated'): ?> <?= htmlspecialchars(__('bundle_market_activated')) ?><?php endif; ?></div>
    <?php if (($activation ?? null) === 'quota_full'): ?>
        <div class="alert alert--warning mb-sm"><?= htmlspecialchars(__('bundle_market_installed_inactive_quota')) ?>
            <a href="<?= route_url('admin.bundles') ?>"><?= htmlspecialchars(__('bundle_quota_overflow_action')) ?></a></div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($uninstalled): ?>
    <div class="alert alert--success mb-sm"><?= htmlspecialchars(__('bundle_market_uninstall_success', ['slug' => $uninstalled])) ?></div>
<?php endif; ?>
<?php if ($channelSaved): ?>
    <div class="alert alert--success mb-sm"><?= htmlspecialchars(__('bundle_market_channel_saved', ['channel' => $channels[$channelSaved] ?? $channelSaved])) ?></div>
<?php endif; ?>
<?php // Seul le code « invalid » a son message ici ; le détail d'un échec (« failed ») est affiché par le layout
// depuis la session (kintai\Core\SessionFlash), jamais lu dans l'URL. ?>
<?php if ($error === 'invalid'): ?>
    <div class="alert alert--danger mb-sm"><?= htmlspecialchars(__('bundle_market_invalid_request')) ?></div>
<?php endif; ?>

<div class="card card--mb">
    <div class="card-body">
        <p class="form-hint mb-sm"><?= __('bundle_market_hint') ?></p>
        <p class="mb-0">
            <?= __('bundle_market_channel_label') ?>
            <span class="btn-group">
                <?php foreach ($channels as $value => $label): ?>
                    <?php $needsConfirm = $value !== 'release' && $value !== $bundleUpdateChannel; ?>
                    <form method="POST" action="<?= htmlspecialchars($channelAction) ?>" class="d-inline"<?= $needsConfirm ? " data-confirm=\"" . htmlspecialchars(__('update_channel_switch_confirm'), ENT_QUOTES) . "\"" : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="channel" value="<?= htmlspecialchars($value) ?>">
                        <?= Button::make($label)->sm()->{$value === $bundleUpdateChannel ? 'primary' : 'outline'}()->submit()->disabled($value === $bundleUpdateChannel)->render() ?>
                    </form>
                <?php endforeach; ?>
            </span>
        </p>
    </div>
</div>

<?php if (empty($entries)): ?>
    <div class="card"><div class="card-body"><p class="form-hint"><?= __('bundle_market_none_found') ?></p></div></div>
<?php endif; ?>

<div class="bundle-market-grid">
    <?php foreach ($entries as $entry):
        $initials = strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $entry['name']) ?: $entry['slug'], 0, 2));
        $chipColor = $bundleChipColor($entry['slug']);
    ?>
        <div class="card bundle-market-card">
            <div class="card-body">
                <div class="bundle-market-card__header">
                    <span class="avatar-chip avatar-chip--lg <?= style_class(['--chip-bg' => $chipColor]) ?>"><?= htmlspecialchars($initials) ?></span>
                    <div class="bundle-market-card__heading">
                        <h3 class="card-title"><?= htmlspecialchars($entry['name']) ?></h3>
                        <div class="bundle-market-card__meta">
                            <?php if ($entry['official']): ?>
                                <?= Badge::make(__('official'))->primary()->xs()->render() ?>
                            <?php else: ?>
                                <?= Badge::make(__('bundle_third_party'))->warning()->xs()->render() ?>
                            <?php endif; ?>
                            <?php if (!$entry['orphaned']): ?>
                                <span class="bundle-market-card__registry"><?= htmlspecialchars($entry['registry_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <p class="text-sm bundle-market-card__desc"><?= htmlspecialchars($entry['description']) ?></p>

                <?php if ($entry['orphaned']): ?>
                    <p class="form-hint text-danger"><?= __('bundle_market_orphaned_hint') ?></p>
                <?php endif; ?>
                <?php if (!$entry['official']): ?>
                    <p class="form-hint text-danger"><?= __('bundle_market_third_party_warning') ?></p>
                <?php endif; ?>

                <div class="bundle-market-card__status">
                    <?php if ($entry['installed_version'] !== null): ?>
                        <?= Badge::make(__('bundle_market_installed_version', ['version' => $entry['installed_version']]))->muted()->sm()->render() ?>
                        <?php if ($entry['update_available']): ?>
                            <?= Badge::make(__('bundle_market_update_available', ['version' => $entry['latest_version']]))->success()->sm()->render() ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= Badge::make(__('bundle_market_not_installed'))->muted()->sm()->render() ?>
                    <?php endif; ?>
                </div>

                <?php
                    $showInstallForm = !$entry['orphaned'] && ($entry['installed_version'] === null || $entry['update_available']);
                    $installFormId = 'bundle-install-' . htmlspecialchars($entry['slug']);
                ?>
                <div class="bundle-market-card__actions">
                    <?php if ($showInstallForm): ?>
                    <form id="<?= $installFormId ?>" method="POST" action="<?= $BASE_URL ?>/admin/bundles/market/install"
                          class="bundle-market-install-form" data-stream-url="<?= $BASE_URL ?>/admin/bundles/market/install/stream"
                          data-dry-run-url="<?= $BASE_URL ?>/admin/bundles/market/dry-run"
                          data-dry-run-ok-label="<?= htmlspecialchars(__('bundle_market_dry_run_ok'), ENT_QUOTES) ?>"
                          data-generic-error-label="<?= htmlspecialchars(__('bundle_market_generic_error'), ENT_QUOTES) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($entry['slug']) ?>">
                        <input type="hidden" name="repository_url" value="<?= htmlspecialchars($entry['repository_url']) ?>">
                        <input type="hidden" name="registry_url" value="<?= htmlspecialchars($entry['registry_url']) ?>">

                        <div class="bundle-market-card__row">
                            <?php if (count($entry['versions']) > 1): ?>
                                <div class="form-group">
                                    <label class="form-label"><?= __('bundle_market_version') ?></label>
                                    <select name="version" class="form-control form-control-sm">
                                        <?php foreach ($entry['versions'] as $v): ?>
                                            <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="version" value="<?= htmlspecialchars($entry['latest_version'] ?? '') ?>">
                            <?php endif; ?>

                            <button type="button" class="btn btn--ghost btn--sm" data-dry-run-btn><?= __('bundle_market_test') ?></button>
                        </div>

                        <?php if (!$entry['official']): ?>
                            <label class="form-check">
                                <input type="checkbox" name="confirm_third_party" value="1" required>
                                <?= __('bundle_market_confirm_third_party') ?>
                            </label>
                        <?php endif; ?>

                        <div class="bundle-market-progress hidden" data-progress>
                            <div class="progress-bar"><div class="progress-bar__fill" data-progress-fill></div></div>
                            <p class="text-sm mt-xs" data-progress-label></p>
                        </div>
                    </form>
                    <?php endif; ?>

                    <?php if ($showInstallForm || $entry['installed_version'] !== null): ?>
                    <div class="bundle-market-card__bottom-row">
                        <?php if ($showInstallForm): ?>
                            <button type="submit" form="<?= $installFormId ?>" class="btn btn--primary btn--sm">
                                <?= $entry['installed_version'] !== null ? __('bundle_market_update') : __('bundle_market_install') ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($entry['installed_version'] !== null): ?>
                            <form method="POST" action="<?= $BASE_URL ?>/admin/bundles/market/uninstall" class="bundle-market-card__uninstall-form"
                                  data-confirm="<?= htmlspecialchars(__('bundle_market_uninstall_confirm', ['name' => $entry['name']]), ENT_QUOTES) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="slug" value="<?= htmlspecialchars($entry['slug']) ?>">
                                <button type="submit" class="btn btn--danger btn--sm"><?= __('bundle_market_uninstall') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script src="<?= $BASE_URL ?>/assets/js/modules/bundle-market.js?v=<?= asset_version() ?>"></script>
