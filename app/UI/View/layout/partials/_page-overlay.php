<?php
/**
 * Zone des fenêtres de page déclarées par les bundles (Bundle::pageOverlays(), contrat 1.27). Une seule fenêtre à la fois ;
 * rien n'est émis (ni script) quand aucune n'est visible. Voir PageOverlayHost.
 */
$_overlayHtml = !empty($auth_user['id'] ?? null)
    ? \kintai\Core\Extension\PageOverlayHost::html(
        $auth_user,
        (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        isset($store_features) && is_array($store_features) ? $store_features : null,
    )
    : '';
?>
<?php if ($_overlayHtml !== ''): ?>
    <?= $_overlayHtml ?>
    <script src="<?= $BASE_URL ?>/assets/js/modules/page-overlay.js?v=<?= asset_version() ?>"></script>
<?php endif; ?>
<?php unset($_overlayHtml); ?>