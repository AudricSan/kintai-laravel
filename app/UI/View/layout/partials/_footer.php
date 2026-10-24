<?php

/**
 * Footer global de l'application — inclus par layout/app.php (utilisateur
 * connecté) et layout/guest.php (page de connexion / pages publiques).
 * Variables disponibles via ViewRenderer::share() : $BASE_URL, $auth_user,
 * $isManager, $app_subtitle, $app_support_email, $app_version.
 *
 * @var string $BASE_URL
 */

$_ftAuthed = !empty($auth_user['id'] ?? null);
$_ftIsOwner = !empty($auth_user['is_admin'] ?? null);
$_ftHomeHref = $_ftAuthed
    ? (!empty($isManager) ? route_url('home') : route_url('employee.dashboard'))
    : route_url('auth.login');
$_ftVersionHref = $_ftIsOwner
    ? route_url('admin.update')
    : 'https://github.com/AudricSan/Kintai#changelog';

// Petite variation d'affichage : une pose différente de Foxy tirée au sort à
// chaque chargement de page plutôt qu'une image toujours identique.
$_ftMascotPose = random_int(1, 4);

$_ftIcon = static function (string $name): string {
    $icons = [
        'github' => '<svg viewBox="0 0 16 16" fill="currentColor" width="16" height="16"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>',
        'contribute' => '<svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M17 6.13V3a1 1 0 0 0-1.7-.7l-3 3a1 1 0 0 0 0 1.4l3 3A1 1 0 0 0 17 9V6.13A7 7 0 0 1 12 20a1 1 0 1 0 0 2 9 9 0 0 0 5-16.5V6.13zM7 17.87V21a1 1 0 0 0 1.7.7l3-3a1 1 0 0 0 0-1.4l-3-3A1 1 0 0 0 7 15v2.87A7 7 0 0 1 12 4a1 1 0 1 0 0-2 9 9 0 0 0-5 16.5v-.63z"/></svg>',
        'roadmap' => '<svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>',
        'history' => '<svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>',
        'bug' => '<svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M20 8h-2.81a5.985 5.985 0 0 0-1.82-1.96L17 4.41 15.59 3l-2.17 2.17a6.002 6.002 0 0 0-2.83 0L8.41 3 7 4.41l1.62 1.63A5.985 5.985 0 0 0 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8zm-6 8h-4v-2h4v2zm0-4h-4v-2h4v2z"/></svg>',
    ];
    return $icons[$name] ?? '';
};
?>
<footer class="app-footer">
    <div class="app-footer__inner">

        <div class="app-footer__brand">
            <a href="<?= $_ftHomeHref ?>" class="app-footer__brand-link">
                <img src="<?= $BASE_URL ?>/assets/img/<?= mascot_path('brand-icon') ?>" alt="" class="app-footer__logo">
                <span class="app-footer__name">Kintai</span>
            </a>
            <p class="app-footer__tagline"><?= __('footer_tagline') ?></p>
            <div class="app-footer__meta">
                <p class="app-footer__copyright">
                    &copy; <?= date('Y') ?> Kintai<?php if (!empty($app_subtitle)): ?> — <?= htmlspecialchars($app_subtitle, ENT_QUOTES) ?><?php endif; ?>
                </p>
                <?php if (!empty($app_version)): ?>
                    <a href="<?= $_ftVersionHref ?>" <?= $_ftIsOwner ? '' : 'target="_blank" rel="noopener noreferrer" ' ?>class="app-footer__version" title="<?= __('footer_version_title') ?>">v<?= htmlspecialchars($app_version, ENT_QUOTES) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <nav class="app-footer__col" aria-label="<?= __('footer_nav_heading') ?>">
            <h4 class="app-footer__heading"><?= __('footer_nav_heading') ?></h4>
            <a href="<?= $_ftHomeHref ?>" class="app-footer__link"><?= __('home') ?></a>
            <a href="<?= route_url('docs.index') ?>" class="app-footer__link"><?= __('footer_help') ?></a>
            <?php if (!empty($app_support_email)): ?>
                <a href="mailto:<?= htmlspecialchars($app_support_email, ENT_QUOTES) ?>" class="app-footer__link"><?= __('footer_contact') ?></a>
            <?php else: ?>
                <a href="https://github.com/AudricSan/Kintai/issues" target="_blank" rel="noopener noreferrer" class="app-footer__link"><?= __('footer_contact') ?></a>
            <?php endif; ?>
        </nav>

        <nav class="app-footer__col" aria-label="<?= __('footer_legal_heading') ?>">
            <h4 class="app-footer__heading"><?= __('footer_legal_heading') ?></h4>
            <a href="<?= route_url('legal.mentions') ?>" class="app-footer__link"><?= __('legal_notice') ?></a>
            <a href="<?= route_url('privacy') ?>" class="app-footer__link"><?= __('privacy_policy_title') ?></a>
            <a href="<?= route_url('legal.terms') ?>" class="app-footer__link"><?= __('terms_of_use') ?></a>
            <a href="<?= route_url('legal.license') ?>" class="app-footer__link"><?= __('license') ?></a>
        </nav>

        <?php if ($_ftAuthed): ?>
            <div class="app-footer__col">
                <h4 class="app-footer__heading"><?= __('footer_support_heading') ?></h4>
                <button type="button" class="app-footer__link app-footer__link--btn" data-on-click="openModal" data-args='["report-issue-modal"]'>
                    <span class="app-footer__icon"><?= $_ftIcon('bug') ?></span><?= __('report_issue') ?>
                </button>
            </div>
        <?php endif; ?>

        <nav class="app-footer__col" aria-label="<?= __('footer_source_heading') ?>">
            <h4 class="app-footer__heading"><?= __('footer_source_heading') ?></h4>
            <a href="https://github.com/AudricSan/Kintai" target="_blank" rel="noopener noreferrer" class="app-footer__link">
                <span class="app-footer__icon"><?= $_ftIcon('github') ?></span><?= __('footer_github') ?>
            </a>
            <a href="https://github.com/AudricSan/Kintai#contributing" target="_blank" rel="noopener noreferrer" class="app-footer__link">
                <span class="app-footer__icon"><?= $_ftIcon('contribute') ?></span><?= __('footer_contributing') ?>
            </a>
            <a href="https://github.com/AudricSan/Kintai#roadmap" target="_blank" rel="noopener noreferrer" class="app-footer__link">
                <span class="app-footer__icon"><?= $_ftIcon('roadmap') ?></span><?= __('footer_roadmap') ?>
            </a>
            <a href="https://github.com/AudricSan/Kintai#changelog" target="_blank" rel="noopener noreferrer" class="app-footer__link">
                <span class="app-footer__icon"><?= $_ftIcon('history') ?></span><?= __('footer_changelog') ?>
            </a>
        </nav>

        <nav class="app-footer__col app-footer__col--mascot">
            <img src="<?= $BASE_URL ?>/assets/img/<?= mascot_path('footer-fox-' . $_ftMascotPose) ?>" alt="" class="app-footer__mascot">
        </nav>

    </div>
</footer>