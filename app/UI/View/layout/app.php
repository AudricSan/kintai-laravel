<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale ?? 'en') ?>" data-mascot="<?= htmlspecialchars(mascot_active(), ENT_QUOTES) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <meta name="theme-color" content="#1a5c8c">
    <link rel="manifest" href="<?= route_url('pwa.manifest') ?>">
    <link rel="icon" href="<?= $BASE_URL ?>/assets/img/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $BASE_URL ?>/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $BASE_URL ?>/assets/img/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $BASE_URL ?>/assets/img/apple-touch-icon.png">
    <title><?= htmlspecialchars($title ?? 'Kintai') ?> — Kintai</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css?v=<?= asset_version() ?>">
    <?php // Couleurs du thème et styles dynamiques de la page (style_class()) : bloc à nonce, aucun attribut de style en ligne.
    $_themeCss = \kintai\UI\InlineStyles::rootVariables($app_theme_color_style ?? ''); ?>
    <?php if ($_themeCss !== ''): ?><style nonce="<?= csp_nonce() ?>"><?= $_themeCss ?></style><?php endif; ?>
    <?= inline_styles() ?>
    <script nonce="<?= csp_nonce() ?>">(function(){var t=localStorage.getItem('kintai-theme');if(t)document.documentElement.dataset.theme=t;}());</script>
</head>

<body>
    <div class="app-layout">
        <?php

        $uri = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $base = rtrim($BASE_URL, '/');
        $path = $base !== '' && str_starts_with($uri, $base)
            ? substr($uri, strlen($base))
            : $uri;
        $path = '/' . trim($path, '/') ?: '/';
        $isOwner    = !empty($auth_user['is_admin']);
        // auth_is_manager (AuthService::isManager(), partagé par AuthMiddleware sur TOUTE
        // page authentifiée) plutôt que isset($managed_store_ids) : ce dernier n'est jamais
        // défini sur les routes sans PermissionMiddleware (/employee/*, /profile, /docs...),
        // ce qui faisait basculer un Manager sur la nav employé réduite dès qu'il quittait
        // une page /admin/* — même rôle, menu different selon la route visitée.
        $isManager  = $auth_is_manager ?? $isOwner;

        $feat = fn(string $f): bool =>
            feat_bundle($f) && (
                $isOwner
                || !isset($store_features) || $store_features === null
                || in_array($f, (array) $store_features, true)
            );

        $svgIcon = static function(string $name): string {
            $icons = [
                'home'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>',
                'calendar'  => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>',
                'users'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
                'clipboard' => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>',
                'clock'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>',
                'leaf'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M17 8C8 10 5.9 16.17 3.82 21.34L5.71 22l1-2.3A4.49 4.49 0 0 0 8 20c4.36 0 9.1-1.76 12.15-5.84C22.17 11.33 21 5.5 17 3c0 2 0 5 0 5z"/></svg>',
                'message'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>',
                'arrows'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z"/></svg>',
                'chart'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>',
                'plus'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>',
                'person'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
                'tag'       => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.41l9 9c.36.36.86.59 1.41.59.55 0 1.05-.23 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>',
                'store'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg>',
                'money'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>',
                'camera'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/></svg>',
                'history'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>',
                'star'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
                'backup'    => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/></svg>',
                'gear'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M19.14,12.94c0.04-0.3,0.06-0.61,0.06-0.94c0-0.32-0.02-0.64-0.07-0.94l2.03-1.58c0.18-0.14,0.23-0.41,0.12-0.61 l-1.92-3.32c-0.12-0.22-0.37-0.29-0.59-0.22l-2.39,0.96c-0.5-0.38-1.03-0.7-1.62-0.94L14.4,2.81c-0.04-0.24-0.24-0.41-0.48-0.41 h-3.84c-0.24,0-0.43,0.17-0.47,0.41L9.25,5.35C8.66,5.59,8.12,5.92,7.63,6.29L5.24,5.33c-0.22-0.08-0.47,0-0.59,0.22L2.74,8.87 C2.62,9.08,2.66,9.34,2.86,9.48l2.03,1.58C4.84,11.36,4.8,11.69,4.8,12s0.02,0.64,0.07,0.94l-2.03,1.58 c-0.18,0.14-0.23,0.41-0.12,0.61l1.92,3.32c0.12,0.22,0.37,0.29,0.59,0.22l2.39-0.96c0.5,0.38,1.03,0.7,1.62,0.94l0.36,2.54 c0.05,0.24,0.24,0.41,0.48,0.41h3.84c0.24,0,0.44-0.17,0.47-0.41l0.36-2.54c0.59-0.24,1.13-0.56,1.62-0.94l2.39,0.96 c0.22,0.08,0.47,0,0.59-0.22l1.92-3.32c0.12-0.22,0.07-0.47-0.12-0.61L19.14,12.94z M12,15.6c-1.98,0-3.6-1.62-3.6-3.6 s1.62-3.6,3.6-3.6s3.6,1.62,3.6,3.6S13.98,15.6,12,15.6z"/></svg>',
                'globe'     => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zm6.93 6h-2.95c-.32-1.25-.78-2.45-1.38-3.56 1.84.63 3.37 1.9 4.33 3.56zM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96zM4.26 14C4.1 13.36 4 12.69 4 12s.1-1.36.26-2h3.38C7.55 10.66 7.5 11.33 7.5 12s.05 1.34.14 2H4.26zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56-1.84-.63-3.37-1.89-4.33-3.56zm2.95-8H5.08c.96-1.66 2.49-2.93 4.33-3.56C8.81 5.55 8.35 6.75 8.03 8zM12 19.96c-.83-1.2-1.48-2.53-1.91-3.96h3.82c-.43 1.43-1.08 2.76-1.91 3.96zM14.34 14H9.66c-.1-.66-.16-1.32-.16-2s.06-1.35.16-2h4.68c.1.65.16 1.32.16 2s-.06 1.34-.16 2zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95c-.96 1.65-2.49 2.93-4.33 3.56zM16.36 14c.09-.66.14-1.33.14-2s-.05-1.34-.14-2h3.38c.16.64.26 1.31.26 2s-.1 1.36-.26 2h-3.38z"/></svg>',
                'book'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 14H8v-2h8v2zm0-4H8v-2h8v2zm0-4H8V6h8v2z"/></svg>',
                'exit'      => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/></svg>',
            ];
            return $icons[$name] ?? '';
        };
        ?>
        <div class="app-sticky-header">
            <?php if (!empty($app_maintenance_mode_enabled)): ?>
                <div class="app-banner" data-app-banner>
                    <span class="app-banner__icon" aria-hidden="true">⚠</span>
                    <span><?= __('maintenance_banner_active') ?></span>
                    <?php if ($isOwner): ?>
                        <a href="<?= route_url('admin.owner_settings') ?>" class="app-banner__link"><?= __('maintenance_banner_manage') ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($password_change_recommended)): ?>
                <?php // Rappel permanent tant que le mot de passe par défaut (ou trop court) n'est pas changé : jamais bloquant. ?>
                <div class="app-banner" data-app-banner>
                    <span class="app-banner__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.65 10A6 6 0 1 0 12.65 14H17v4h4v-4h2v-4H12.65zM7 14a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg></span>
                    <span><?= __('password_reminder_banner') ?></span>
                    <a href="<?= route_url('profile') ?>?tab=info" class="app-banner__link"><?= __('password_reminder_action') ?></a>
                </div>
            <?php endif; ?>

            <?php if ($isOwner && !empty($storage_low)): ?>
                <?php // Stockage du serveur presque plein : réservé à l'Owner, disparaît dès que de l'espace est libéré. ?>
                <div class="app-banner" data-app-banner>
                    <span class="app-banner__icon" aria-hidden="true">⚠</span>
                    <span><?= htmlspecialchars(__('storage_low_banner', ['free' => $storage_low['free'], 'total' => $storage_low['total'], 'percent' => $storage_low['percent'], 'backups' => $storage_low['backups']])) ?></span>
                    <a href="<?= route_url('admin.backup') ?>" class="app-banner__link"><?= __('storage_low_action') ?></a>
                </div>
            <?php endif; ?>

            <?php include __DIR__ . '/partials/_topbar.php'; ?>
        </div>
        <?php if (!empty($app_maintenance_mode_enabled) || !empty($password_change_recommended) || ($isOwner && !empty($storage_low))): ?>
            <script nonce="<?= csp_nonce() ?>">
            (function () {
                // Hauteur cumulée des bandeaux au-dessus de la barre du haut (maintenance, mot de passe, stockage) : sert au
                // positionnement des éléments collants sous l'en-tête (voir --maintenance-banner-h, responsive.css).
                var banners = document.querySelectorAll('[data-app-banner]');
                if (!banners.length) return;
                var setOffset = function () {
                    var h = 0;
                    banners.forEach(function (b) { h += b.offsetHeight; });
                    document.documentElement.style.setProperty('--maintenance-banner-h', h + 'px');
                };
                setOffset();
                window.addEventListener('resize', setOffset);
            }());
            </script>
        <?php endif; ?>

        <div class="page-content">
            <?php foreach (\kintai\Core\SessionFlash::pull() as $_flash): ?>
                <div class="alert alert--<?= htmlspecialchars($_flash['type']) ?> mb-sm"><?= htmlspecialchars($_flash['text']) ?></div>
            <?php endforeach; ?>
            <?php include __DIR__ . '/partials/_old-input.php'; ?>
            <?php if ($isOwner && !empty($app_public_url_missing)): ?>
                <div class="alert alert--warning mb-sm">
                    <?= htmlspecialchars(__('public_url_missing_warning')) ?>
                    <a href="<?= route_url('admin.owner_settings') ?>"><?= htmlspecialchars(__('public_url_missing_action')) ?></a>
                </div>
            <?php endif; ?>
            <?php if ($isOwner && !empty($setup_wizard_pending) && !str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), base_url() . '/admin/setup')): ?>
                <div class="alert alert--info mb-sm">
                    <?= htmlspecialchars(__('setup_pending_banner')) ?>
                    <a href="<?= route_url('admin.setup') ?>"><?= htmlspecialchars(__('setup_pending_action')) ?></a>
                </div>
            <?php endif; ?>
            <?php if ($isOwner && !empty($bundle_quota_overflow)): ?>
                <div class="alert alert--warning mb-sm">
                    <?= htmlspecialchars(__('bundle_quota_overflow_warning', ['active' => $bundle_quota_overflow['active'], 'max' => $bundle_quota_overflow['max']])) ?>
                    <a href="<?= route_url('admin.bundles') ?>"><?= htmlspecialchars(__('bundle_quota_overflow_action')) ?></a>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </div>

        <?php include __DIR__ . '/partials/_footer.php'; ?>

        <?php include __DIR__ . '/partials/_bottomnav.php'; ?>
    </div>

    <script src="<?= $BASE_URL ?>/assets/js/modules/csp-actions.js?v=<?= asset_version() ?>"></script>
    <script src="<?= $BASE_URL ?>/assets/js/app.js?v=<?= asset_version() ?>"></script>
    <script src="<?= $BASE_URL ?>/assets/js/modules/notifications.js?v=<?= asset_version() ?>"></script>
    <script src="<?= $BASE_URL ?>/assets/js/modules/old-input.js?v=<?= asset_version() ?>"></script>
    <script src="<?= $BASE_URL ?>/assets/js/modules/user-picker.js?v=<?= asset_version() ?>"></script>
    <?php /* Web push natif : chargé sur toutes les pages connectées pour resynchroniser l'abonnement du navigateur. */ ?>
    <?php if (!empty($push_web_config) && !empty($auth_user['id'] ?? null)): ?>
        <div id="push-meta"
             data-vapid-key="<?= htmlspecialchars($push_web_config['vapid_key']) ?>"
             data-subscribe-url="<?= route_url('notifications.push_subscribe') ?>"
             data-unsubscribe-url="<?= route_url('notifications.push_unsubscribe') ?>"
             data-status-url="<?= route_url('notifications.push_status') ?>"
             data-user-id="<?= (int) $auth_user['id'] ?>"
             hidden></div>
        <script src="<?= $BASE_URL ?>/assets/js/modules/push.js?v=<?= asset_version() ?>"></script>
    <?php endif; ?>
    <script nonce="<?= csp_nonce() ?>">
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('<?= $BASE_URL ?>/sw.js', { scope: '<?= $BASE_URL ?>/' }).catch(function () {});
        });
    }
    </script>
    <script nonce="<?= csp_nonce() ?>">document.addEventListener('click',function(e){if(e.target.closest('a,button,input,select,textarea,form'))return;var t=e.target.closest('.tr--clickable[data-href]');if(t){location.href=t.getAttribute('data-href');return;}var m=e.target.closest('.tr--clickable[data-modal]');if(m&&window.openModal){window.openModal(m.getAttribute('data-modal'));}});</script>

    <?php if (!empty($auth_user['id'] ?? null)): ?>
        <?php include __DIR__ . '/partials/report-issue-modal.php'; ?>
    <?php endif; ?>

    <?php include __DIR__ . '/partials/_confirm-modal.php'; ?>
    <?php include __DIR__ . '/partials/_page-overlay.php'; ?>
    <?php if (!empty($password_reminder_popup)) {
        include __DIR__ . '/partials/_password-reminder-modal.php';
    } ?>
    <?php // Règles ajoutées par les partiels du layout lui-même, rendus après le <head>. ?>
    <?= inline_styles() ?>
</body>

</html>