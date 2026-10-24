<?php
/** @var string      $lang           Langue courante ('fr'|'en'|'ja') */
/** @var string      $page           Slug de la page courante (sans extension) */
/** @var array       $toc            [{label, items: [{slug, title, available, github_url}]}] */
/** @var array       $flatItems      [{slug, title, available, github_url}] à plat, dans l'ordre du sommaire */
/** @var int|null    $currentIndex   Index de $page dans $flatItems */
/** @var string      $contentHtml    Contenu HTML déjà rendu (Parsedown) */
/** @var array       $otherLanguages ['fr'|'en'|'ja' => bool] page courante disponible dans cette langue */
/** @var string      $githubWikiUrl  URL de la page courante sur le wiki GitHub */
/** @var bool        $isOwner        L'utilisateur courant est-il propriétaire (peut mettre à jour le wiki) */

$prev = ($currentIndex !== null && $currentIndex > 0) ? $flatItems[$currentIndex - 1] : null;
$next = ($currentIndex !== null && $currentIndex < count($flatItems) - 1) ? $flatItems[$currentIndex + 1] : null;

$langFlags = ['fr' => '🇫🇷', 'en' => '🇬🇧', 'ja' => '🇯🇵'];
?>

<div class="page-header">
    <h2 class="page-header__title"><?= __('docs_title') ?></h2>
    <p class="page-header__subtitle">
        <a href="<?= htmlspecialchars($githubWikiUrl) ?>" target="_blank" rel="noopener noreferrer">
            🔗 <?= __('docs_view_on_github') ?> ↗
        </a>
        <?php if ($isOwner): ?>
            ·
            <button id="btnWikiSync" type="button" class="docs-wiki__sync-link" data-on-click="syncWiki">
                ↻ <?= __('docs_sync_btn_update') ?>
            </button>
            <span id="wikiSyncStatus" class="docs-sync__status" aria-live="polite"></span>
        <?php endif; ?>
    </p>
</div>

<?php if ($otherLanguages !== []): ?>
<div class="docs-wiki__lang-switch">
    <?php foreach ($otherLanguages as $l => $available): ?>
        <?php if ($available): ?>
            <a href="<?= route_url('docs.show', ['lang' => $l, 'page' => $page]) ?>" class="docs-wiki__lang-link">
                <?= $langFlags[$l] ?? $l ?> <?= strtoupper($l) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="docs-wiki">
    <nav class="docs-wiki__sidebar" aria-label="<?= htmlspecialchars(__('docs_toc_title')) ?>">
        <img src="<?= ($BASE_URL ?? '') ?>/assets/img/<?= mascot_path('docs-hero') ?>" alt="<?= __('mascot_alt') ?>" class="docs-wiki__mascot">
        <?php foreach ($toc as $group): ?>
            <?php if ($group['label'] !== null): ?>
                <h4 class="docs-wiki__toc-title"><?= htmlspecialchars($group['label']) ?></h4>
            <?php endif; ?>
            <ul class="docs-wiki__toc-list">
                <?php foreach ($group['items'] as $entry): ?>
                    <li>
                        <?php if ($entry['available']): ?>
                            <a href="<?= route_url('docs.show', ['lang' => $lang, 'page' => $entry['slug']]) ?>"
                               class="docs-wiki__toc-link<?= $entry['slug'] === $page ? ' docs-wiki__toc-link--active' : '' ?>">
                                <?= htmlspecialchars($entry['title']) ?>
                            </a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($entry['github_url']) ?>"
                               class="docs-wiki__toc-link docs-wiki__toc-link--unavailable"
                               target="_blank" rel="noopener noreferrer"
                               title="<?= htmlspecialchars(__('docs_view_on_github')) ?>">
                                <?= htmlspecialchars($entry['title']) ?> ↗
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>

    <div class="card docs-wiki__content-card">
        <div class="card-body docs-wiki__content">
            <?= $contentHtml ?>
        </div>

        <div class="docs-wiki__pager">
            <?php if ($prev): ?>
                <a href="<?= route_url('docs.show', ['lang' => $lang, 'page' => $prev['slug']]) ?>" class="btn btn--ghost docs-wiki__pager-link docs-wiki__pager-link--prev">
                    &larr; <?= htmlspecialchars($prev['title']) ?>
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>

            <?php if ($next): ?>
                <a href="<?= route_url('docs.show', ['lang' => $lang, 'page' => $next['slug']]) ?>" class="btn btn--ghost docs-wiki__pager-link docs-wiki__pager-link--next">
                    <?= htmlspecialchars($next['title']) ?> &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isOwner): ?>
<script nonce="<?= csp_nonce() ?>">
var _wikiSyncUrl = <?= json_encode(route_url('docs.sync')) ?>;

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
                status.textContent = '✓ <?= __('docs_sync_ok') ?>'
                    + ' (' + '<?= __('docs_sync_added') ?>: ' + data.added
                    + ', ' + '<?= __('docs_sync_updated') ?>: ' + data.updated
                    + ', ' + '<?= __('docs_sync_unchanged') ?>: ' + data.unchanged + ')';
                setTimeout(function() { location.reload(); }, 1200);
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
