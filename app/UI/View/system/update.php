<?php
use kintai\UI\Components\Badge;
use kintai\UI\Components\Button;
use kintai\UI\Components\Card;
use kintai\UI\Components\Modal;

/**
 * @var string      $currentVersion
 * @var array|null  $updateInfo
 * @var string|null $updateCheckError
 * @var string      $updateChannel
 * @var array       $pendingMigs
 * @var string      $releaseNotesCondensed
 * @var array<string, array<int, array{version:string, notes:string, release_url:?string, published_at:?string}>> $otherChannelsHistory
 * @var string      $repoReleasesUrl
 * @var string      $BASE_URL
 * @var array{type: 'success'|'danger', text: string}|null $flash
 */

/** Notes de la dernière release, condensées à 1 point par catégorie côté contrôleur, rendues depuis le Markdown (titres, listes). */
$notesSummary = trim($releaseNotesCondensed);

$action = route_url('admin.update');
$channelAction = route_url('admin.update.channel');
$channels = ['release' => __('update_channel_release'), 'beta' => __('update_channel_beta'), 'alpha' => __('update_channel_alpha')];
?>
<?php if ($flash !== null): ?>
    <div class="alert alert--<?= $flash['type'] ?> mb-sm"><?= htmlspecialchars($flash['text']) ?></div>
<?php endif; ?>
<div class="page-header">
    <h2 class="page-header__title"><?= __('update_title') ?></h2>
</div>

<?php include __DIR__ . '/../_partials/_settings-tabs.php'; ?>

<?php
ob_start();
?>
<p class="mb-sm">
    <?= __('update_channel_label') ?>
    <span class="btn-group">
        <?php foreach ($channels as $value => $label): ?>
            <?php $needsConfirm = $value !== 'release' && $value !== $updateChannel; ?>
            <form method="POST" action="<?= htmlspecialchars($channelAction) ?>" class="d-inline"<?= $needsConfirm ? " data-confirm=\"" . htmlspecialchars(__('update_channel_switch_confirm'), ENT_QUOTES) . "\"" : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="channel" value="<?= htmlspecialchars($value) ?>">
                <?= Button::make($label)->sm()->{$value === $updateChannel ? 'primary' : 'outline'}()->submit()->disabled($value === $updateChannel)->render() ?>
            </form>
        <?php endforeach; ?>
    </span>
</p>
<p>
    <?= __('backup_current_version') ?> <strong><?= htmlspecialchars($currentVersion) ?></strong>
    <?php if ($pendingMigs !== []): ?>
        <?= Badge::make(sprintf(__('backup_pending_migrations'), count($pendingMigs)))->warning()->render() ?>
        <form method="POST" action="<?= htmlspecialchars($action) ?>/migrate" class="d-inline">
            <?= csrf_field() ?>
            <?= Button::make(__('backup_run_migrations'))->sm()->primary()->submit()->render() ?>
        </form>
    <?php endif; ?>
</p>
<?php if ($updateInfo !== null): ?>
    <?php if ($updateInfo['has_update']): ?>
        <div class="alert alert--info mt-sm">
            <strong><?= __('backup_update_available') ?></strong> :
            <?= htmlspecialchars($updateInfo['current_version']) ?> →
            <strong><?= htmlspecialchars($updateInfo['latest_version']) ?></strong>
            <?php if ($updateInfo['release_url']): ?>
                <br><a href="<?= htmlspecialchars($updateInfo['release_url']) ?>" target="_blank" rel="noopener"><?= __('backup_release_notes') ?></a>
            <?php endif; ?>
            <form id="backup-update-form" method="POST" action="<?= htmlspecialchars($action) ?>/apply" class="mt-sm" data-stream-url="<?= htmlspecialchars($action) ?>/stream" data-confirm="<?= htmlspecialchars(__('backup_update_confirm'), ENT_QUOTES) ?>">
                <?= csrf_field() ?>
                <?= Button::make(__('backup_update_now_btn'))->danger()->submit()->render() ?>
            </form>
            <div id="backup-update-progress" class="hidden mt-sm" data-summary-template="<?= htmlspecialchars(__('backup_update_done_summary')) ?>">
                <div class="progress-bar"><div class="progress-bar__fill" id="backup-update-fill"></div></div>
                <p class="text-sm mt-xs" id="backup-update-label"></p>
            </div>
        </div>
        <?php if (!empty($incompatibleBundles)): ?>
        <?php // Bundles installés que cette version du Core ne chargera plus (contrat non accepté). ?>
        <div class="alert alert--warning mt-sm">
            <strong><?= __('update_bundles_incompatible_title') ?></strong>
            <p class="text-sm mt-xs"><?= htmlspecialchars(__('update_bundles_incompatible_hint', ['contract' => (string) $targetContract])) ?></p>
            <ul class="text-sm">
                <?php foreach ($incompatibleBundles as $_b): ?>
                <li><strong><?= htmlspecialchars($_b['name']) ?></strong> v<?= htmlspecialchars($_b['version']) ?> — <?= htmlspecialchars($_b['reason']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert--success mt-sm"><?= sprintf(__('backup_up_to_date'), htmlspecialchars($currentVersion)) ?></div>
    <?php endif; ?>
<?php elseif (!empty($updateCheckError)): ?>
    <div class="alert alert--warning mt-sm">
        <?= sprintf(__('backup_update_check_failed'), htmlspecialchars($updateCheckError)) ?>
    </div>
<?php else: ?>
    <p class="text-muted mt-sm"><?= __('backup_no_update_server') ?></p>
<?php endif; ?>
<?php echo Card::make()->header(__('backup_instance_version'))->body(ob_get_clean())->render(); ?>

<?php if (array_filter($otherChannelsHistory) !== []): ?>
    <?php
    ob_start();
    ?>
    <?php foreach ($otherChannelsHistory as $channel => $releases): ?>
        <?php if ($releases === []): continue; endif; ?>
        <details class="update-channel-history">
            <summary>
                <?= Badge::make($channels[$channel] ?? $channel)->{$channel === 'alpha' ? 'danger' : 'warning'}()->render() ?>
                <?= sprintf(__('update_channel_history_count'), count($releases)) ?>
            </summary>
            <?php foreach ($releases as $release): ?>
                <div class="update-notes-item">
                    <div class="update-notes-item-header">
                        <span class="update-notes-item-version">v<?= htmlspecialchars($release['version']) ?></span>
                        <?php if ($release['published_at']): ?>
                            <span class="update-notes-item-date"><?= htmlspecialchars(date('d/m/Y', strtotime($release['published_at']))) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($release['notes'] !== ''): ?>
                        <div class="update-notes-content update-notes-item-body"><?= render_markdown($release['notes']) ?></div>
                    <?php else: ?>
                        <p class="text-muted text-sm"><?= __('update_notes_empty') ?></p>
                    <?php endif; ?>
                    <?php if ($release['release_url']): ?>
                        <a href="<?= htmlspecialchars($release['release_url']) ?>" target="_blank" rel="noopener"><?= __('backup_release_notes') ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
    <?php
    echo Card::make()->header(__('update_channel_history_title'))->body(ob_get_clean())->render();
    ?>
<?php endif; ?>

<?php if ($updateInfo !== null): ?>
    <?php
    ob_start();
    ?>
    <div class="update-notes-item-header">
        <span class="update-notes-item-version">v<?= htmlspecialchars($updateInfo['latest_version']) ?></span>
        <?php if ($updateInfo['published_at']): ?>
            <span class="update-notes-item-date"><?= htmlspecialchars(date('d/m/Y', strtotime($updateInfo['published_at']))) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($notesSummary !== ''): ?>
        <div class="update-notes-content update-notes-summary"><?= render_markdown($notesSummary) ?></div>
    <?php else: ?>
        <p class="text-muted"><?= __('update_notes_empty') ?></p>
    <?php endif; ?>
    <p class="btn-group mt-sm">
        <?php if ($notesSummary !== ''): ?>
            <?= Button::make(__('update_notes_view_more'))->sm()->outline()->attrs(['data-on-click' => 'openModal', 'data-args' => '["update-notes-modal"]'])->render() ?>
        <?php endif; ?>
        <?= Button::make(__('update_notes_github_btn'))->sm()->ghost()->link($repoReleasesUrl)->attrs(['target' => '_blank', 'rel' => 'noopener'])->render() ?>
    </p>
    <?php
    echo Card::make()->header(__('update_notes_summary_title'))->body(ob_get_clean())->render();
    ?>
<?php endif; ?>

<?php if ($updateInfo !== null && $notesSummary !== ''): ?>
    <?php
    ob_start();
    ?>
    <div class="update-notes-item">
        <div class="update-notes-item-header">
            <span class="update-notes-item-version">v<?= htmlspecialchars($updateInfo['latest_version']) ?></span>
            <?php if ($updateInfo['published_at']): ?>
                <span class="update-notes-item-date"><?= htmlspecialchars(date('d/m/Y', strtotime($updateInfo['published_at']))) ?></span>
            <?php endif; ?>
        </div>
        <div class="update-notes-content update-notes-item-body"><?= render_markdown($notesSummary) ?></div>
        <?php if ($updateInfo['release_url']): ?>
            <a href="<?= htmlspecialchars($updateInfo['release_url']) ?>" target="_blank" rel="noopener"><?= __('backup_release_notes') ?></a>
        <?php endif; ?>
    </div>
    <?php
    $notesModalBody = ob_get_clean();

    $notesModalFooter = Button::make(__('update_notes_github_btn'))->outline()->link($repoReleasesUrl)->attrs(['target' => '_blank', 'rel' => 'noopener'])->render();

    echo Modal::make('update-notes-modal')
        ->title(__('update_notes_modal_title'))
        ->body($notesModalBody)
        ->footer($notesModalFooter)
        ->wide()
        ->render();
    ?>
<?php endif; ?>

<script src="<?= $BASE_URL ?>/assets/js/modules/backup-update.js"></script>
