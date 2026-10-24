<?php
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;

/** @var array<string, mixed> $result bilan de l'installation (installed, inactive, failed) */
ob_start();
?>
    <?php if (!empty($result['installed'])): ?>
        <div class="alert alert--success mb-sm"><?= htmlspecialchars(__('setup_done_installed', ['list' => implode(', ', $result['installed'])])) ?></div>
    <?php endif; ?>
    <?php if (!empty($result['inactive'])): ?>
        <div class="alert alert--warning mb-sm"><?= htmlspecialchars(__('setup_done_inactive', ['list' => implode(', ', $result['inactive'])])) ?></div>
    <?php endif; ?>
    <?php if (!empty($result['pending'])): ?>
        <div class="alert alert--info mb-sm">
            <?= htmlspecialchars(__('setup_pending_waiting', ['list' => implode(', ', $result['pending'])])) ?>
            <a href="<?= route_url('admin.setup') ?>?step=license"><?= __('setup_sel_activate_license') ?></a>
        </div>
    <?php endif; ?>
    <?php if (!empty($result['failed'])): ?>
        <div class="alert alert--warning mb-sm">
            <?= htmlspecialchars(__('setup_done_failed')) ?>
            <ul class="text-sm">
                <?php foreach ($result['failed'] as $slug => $reason): ?>
                    <li><strong><?= htmlspecialchars((string) $slug) ?></strong> — <?= htmlspecialchars((string) $reason) ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= route_url('admin.bundles.market') ?>"><?= htmlspecialchars(__('setup_done_open_market')) ?></a>
        </div>
    <?php endif; ?>
    <div class="form-actions">
        <?= Button::make(__('setup_continue'))->primary()->link(route_url('admin.setup') . '?step=' . $next)->render() ?>
    </div>
<?php echo Card::make()->header(__('setup_bundles_title'))->body(ob_get_clean())->render(); ?>
