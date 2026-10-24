<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Flash;

/**
 * Paramètres de navigation admin/manager. L'employé gère son menu depuis
 * l'onglet "nav" de son profil (staff/profile.php) — pas de duplication ici.
 *
 * @var string[] $hidden
 * @var string[] $sectionOrder
 * @var string[] $defaultSections
 * @var string[] $bottomNavItems
 * @var bool|null $isOwner          owner vs manager
 * @var mixed    $store_features
 * @var string   $BASE_URL
 */

$isOwnerView = !empty($isOwner);
$hasFeat     = fn(?string $f): bool => $f === null || $isOwnerView || $store_features === null || in_array($f, (array) $store_features, true);

// Entrées masquables construites à partir de la navigation réelle (Core + bundles actifs, voir
// kintai\Core\Extension\Navigation) : même liste que la barre du haut, plus de table recopiée à la main.
$_navigation = \kintai\Core\Extension\Navigation::current();
$_byNav = $_navigation->hideableKeys(true, $route_visible ?? fn(string $r): bool => true, $hasFeat, get_defined_vars());


$sections = [];
foreach ($sectionOrder as $sk) {
    if (!empty($_byNav[$sk])) {
        $sections[$sk] = $_byNav[$sk];
    }
}
// Barre mobile : entrées « mobile » de la navigation réelle, permises et actives pour ce magasin.
$bottomNavPool = $_navigation->mobileChoices(true, $route_visible ?? fn(string $r): bool => true, $hasFeat);

echo Flash::fromQuery('success', ['default' => __('operation_success'), '1' => __('operation_success')])->render();
?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('nav_settings') ?></h2>
</div>

<form method="POST" action="<?= route_url('admin.nav_settings') ?>">
    <?= csrf_field() ?>

    <?php
    ob_start();
    ?>
    <div class="form-inline-flex mb-sm">
        <p class="text-muted flex-1"><?= __('nav_section_order_desc') ?></p>
        <?= Button::make(__('nav_reset_order'))->ghost()->sm()->submit()->attrs(['name' => 'reset_section_order', 'value' => '1', 'data-confirm' => __('nav_reset_order') . ' ?'])->render() ?>
    </div>
    <ul class="nav-order-list" id="navSectionOrder">
        <?php foreach ($sectionOrder as $secKey): if (!isset($sections[$secKey])) continue; ?>
            <li class="nav-order-item" draggable="true" data-key="<?= htmlspecialchars($secKey) ?>">
                <span class="nav-order-handle" aria-hidden="true">⠿</span>
                <span class="nav-order-label"><?= __($secKey) ?></span>
                <div class="nav-order-arrows">
                    <button type="button" class="nav-order-btn nav-order-btn--up" aria-label="<?= __('nav_move_up') ?>">▲</button>
                    <button type="button" class="nav-order-btn nav-order-btn--down" aria-label="<?= __('nav_move_down') ?>">▼</button>
                </div>
                <input type="hidden" name="section_order[]" value="<?= htmlspecialchars($secKey) ?>">
            </li>
        <?php endforeach; ?>
    </ul>
    <?php echo Card::make()->body(ob_get_clean())->render(); ?>

    <?php
    ob_start();
    ?>
    <p class="text-muted mb-sm"><?= __('nav_settings_desc') ?></p>
    <div class="form-group mb-sm">
        <div class="nav-pref-item nav-pref-item--fixed">
            <label class="form-toggle">
                <input type="checkbox" class="form-toggle__input" checked disabled>
                <span class="form-toggle__track"></span>
            </label>
            <span class="nav-pref-label"><?= __('dashboard') ?></span>
            <?= Badge::make(__('nav_always_visible'))->muted()->sm()->render() ?>
        </div>
    </div>
    <?php foreach ($sectionOrder as $sectionKey): if (!isset($sections[$sectionKey])) continue; ?>
        <div class="nav-pref-section">
            <div class="nav-pref-section-title"><?= __($sectionKey) ?></div>
            <?php foreach ($sections[$sectionKey] as $key): ?>
            <div class="nav-pref-item">
                <label class="form-toggle">
                    <input type="checkbox" name="nav[<?= $key ?>]" value="1" class="form-toggle__input" <?= !in_array($key, $hidden, true) ? 'checked' : '' ?>>
                    <span class="form-toggle__track"></span>
                </label>
                <span class="nav-pref-label"><?= __($_navigation->labelOf($key, true)) ?></span>
                <input type="hidden" name="nav_available[]" value="<?= htmlspecialchars($key) ?>">
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php echo Card::make()->body(ob_get_clean())->render(); ?>

    <?php
    ob_start();
    ?>
    <p class="text-muted mb-sm"><?= __('bottom_nav_config_desc') ?></p>
    <p class="text-muted mb-sm text--sm hidden" id="bottomNavHint"><?= __('bottom_nav_min_max') ?></p>
    <div class="form-group mb-sm">
        <div class="nav-pref-item nav-pref-item--fixed">
            <label class="form-toggle">
                <input type="checkbox" class="form-toggle__input" checked disabled>
                <span class="form-toggle__track"></span>
            </label>
            <span class="nav-pref-label"><?= __('home') ?></span>
            <?= Badge::make(__('nav_always_visible'))->muted()->sm()->render() ?>
        </div>
    </div>
    <?php foreach ($bottomNavPool as $_bnKey): ?>
    <div class="nav-pref-item">
        <label class="form-toggle">
            <input type="checkbox" name="bottom_nav[]" value="<?= htmlspecialchars($_bnKey) ?>" class="form-toggle__input bottom-nav-check" <?= in_array($_bnKey, $bottomNavItems, true) ? 'checked' : '' ?>>
            <span class="form-toggle__track"></span>
        </label>
        <span class="nav-pref-label"><?= __($_navigation->labelOf($_bnKey, true)) ?></span>
    </div>
    <?php endforeach; ?>
    <?php echo Card::make()->body(ob_get_clean())->render(); ?>

    <div class="form-actions">
        <?= Button::make(__('save'))->primary()->submit()->render() ?>
        <a href="<?= route_url('home') ?>" class="btn btn--ghost"><?= __('cancel') ?></a>
    </div>
</form>

<script src="<?= $BASE_URL ?>/assets/js/modules/nav-order.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/modules/bottom-nav-config.js"></script>
