<?php
/** @var string $locale        Locale courante de l'utilisateur ('fr'|'en'|'ja') */
/** @var string $githubWikiUrl URL de la page d'accueil du wiki GitHub dans cette langue */
/** @var bool   $isOwner       L'utilisateur courant est-il propriétaire (peut cloner le wiki) */

$langLabels = ['fr' => 'Français', 'en' => 'English', 'ja' => '日本語'];
$langLabel  = $langLabels[$locale] ?? $locale;
?>

<div class="page-header">
    <h2 class="page-header__title"><?= __('docs_title') ?></h2>
</div>

<div class="card docs-unavailable">
    <div class="card-body">
        <img src="<?= ($BASE_URL ?? '') ?>/assets/img/<?= mascot_path('docs-hero') ?>" alt="<?= __('mascot_alt') ?>" class="docs-unavailable__mascot">
        <p class="docs-unavailable__message">
            <?= __('docs_unavailable_message', ['lang' => $langLabel]) ?>
        </p>

        <a href="<?= htmlspecialchars($githubWikiUrl) ?>"
           class="btn btn--outline docs-unavailable__btn"
           target="_blank" rel="noopener noreferrer">
            🔗 <?= __('docs_view_on_github') ?> ↗
        </a>

        <?php if ($isOwner): ?>
        <div class="docs-unavailable__sync">
            <p class="docs-unavailable__sync-hint"><?= __('docs_sync_body_missing') ?></p>
            <div class="docs-sync__row">
                <button id="btnWikiSync" type="button" class="btn btn--primary docs-sync__btn" data-on-click="syncWiki">
                    ↻ <?= __('docs_sync_btn_clone') ?>
                </button>
                <span id="wikiSyncStatus" class="docs-sync__status" aria-live="polite"></span>
            </div>
        </div>

        <script nonce="<?= csp_nonce() ?>">
        var _wikiSyncUrl = <?= json_encode(route_url('docs.sync')) ?>;
        var _wikiIndexUrl = <?= json_encode(route_url('docs.index')) ?>;

        function syncWiki() {
            var btn    = document.getElementById('btnWikiSync');
            var status = document.getElementById('wikiSyncStatus');

            btn.disabled = true;
            status.className = 'docs-sync__status';
            status.textContent = '<?= __('docs_sync_running') ?>…';

            fetch(_wikiSyncUrl, { method: 'POST' })
                .then(function(r) { return r.json().then(function(data) { return { status: r.status, data: data }; }); })
                .then(function(res) {
                    var data = res.data;
                    if (res.status === 200 && data.ok) {
                        status.className = 'docs-sync__status docs-sync__status--ok';
                        status.textContent = '✓ <?= __('docs_sync_ok') ?>';
                        setTimeout(function() { location.href = _wikiIndexUrl; }, 1000);
                    } else {
                        status.className = 'docs-sync__status docs-sync__status--err';
                        status.textContent = '✗ ' + ((data.errors && data.errors[0]) || '<?= __('docs_sync_err') ?>');
                        btn.disabled = false;
                    }
                })
                .catch(function() {
                    status.className = 'docs-sync__status docs-sync__status--err';
                    status.textContent = '✗ <?= __('docs_sync_err') ?>';
                    btn.disabled = false;
                });
        }
        </script>
        <?php endif; ?>
    </div>
</div>
