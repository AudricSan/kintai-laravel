<?php
/**
 * Sous-navigation partagée entre les trois écrans de gestion des bundles :
 * la liste activable/désactivable (/admin/bundles), le catalogue agrégé des
 * registries (/admin/bundles/market) et la gestion des registries eux-mêmes
 * (/admin/bundles/registries). Avant cette navigation, chaque page ne liait
 * que vers les deux autres via des boutons ad hoc dans sa carte de hint, sans
 * jamais indiquer la page courante, et bundle-registries.php n'avait même
 * aucun lien de retour.
 * @var string|null $BASE_URL
 */

$_uri = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$_base = rtrim($BASE_URL ?? '', '/');
$_bundlePath = $_base !== '' && str_starts_with($_uri, $_base) ? substr($_uri, strlen($_base)) : $_uri;
$_bundlePath = '/' . trim($_bundlePath, '/') ?: '/';

$_bundleTabs = [
    ['label' => __('bundle_settings'),   'url' => route_url('admin.bundles'),            'match' => '/admin/bundles',            'exact' => true],
    ['label' => __('bundle_market'),     'url' => route_url('admin.bundles.market'),     'match' => '/admin/bundles/market'],
    ['label' => __('bundle_registries'), 'url' => route_url('admin.bundles.registries'), 'match' => '/admin/bundles/registries'],
];
?>
<div class="tabs card--mb">
    <?php foreach ($_bundleTabs as $_t): ?>
        <?php $_active = !empty($_t['exact']) ? $_bundlePath === $_t['match'] : str_starts_with($_bundlePath, $_t['match']); ?>
        <a href="<?= $_t['url'] ?>" class="tab<?= $_active ? ' tab--active' : '' ?>">
            <?= $_t['label'] ?>
        </a>
    <?php endforeach; ?>
</div>
