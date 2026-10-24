<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Database\MigrationRunner;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\SessionFlash;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\BackupService;
use kintai\Core\BundleDiscoveryService;
use kintai\Core\Services\GithubUpdateService;
use kintai\Core\Services\UpdateService;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

final class BackupController
{
    use HasBaseUrl;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly BackupService $backup,
        private readonly UpdateService $update,
        private readonly GithubUpdateService $githubUpdate,
        private readonly MigrationRunner $migrator,
        private readonly AppSettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** GET /admin/backup/download?filename= — télécharge une archive de sauvegarde existante. */
    public function download(Request $request): Response
    {

        $filename = (string) $request->query('filename', '');
        $path = $this->backup->getPath($filename);
        if ($filename === '' || !file_exists($path)) {
            return Response::redirect($this->base() . '/admin/backup?success=error_not_found');
        }

        return Response::fileStream($path, 'application/zip', basename($path));
    }

    public function index(Request $request): Response
    {

        $backups = $this->backup->list();
        $flash = $this->describeFlash((string) $request->query('success', ''));

        return Response::html($this->view->render('system.backup', [
            'title'   => 'Sauvegardes',
            'backups' => $backups,
            'flash'   => $flash,
            'backup_auto_enabled' => $this->settings->backupAutoEnabled() ? '1' : '0',
            'backup_max_keep'     => $this->settings->backupMaxKeep(),
        ], 'layout.app'));
    }

    /** POST /admin/backup/settings — sauvegarde automatique (cron) et rétention. */
    public function saveSettings(Request $request): Response
    {

        // Champ absent : réglage actuel conservé (audit du 13/10/2026, M12).
        $autoEnabled = (string) $request->postFlag('backup_auto_enabled', $this->settings->get('backup_auto_enabled', '0') === '1');
        $maxKeep = max(0, min(365, (int) $request->post('backup_max_keep', $this->settings->get('backup_max_keep', '0'))));

        $this->settings->setMany([
            'backup_auto_enabled' => $autoEnabled,
            'backup_max_keep'     => (string) $maxKeep,
        ]);

        $this->auditLogger->log($request, 'backup.settings_updated', 'system', null, [
            'backup_auto_enabled' => $autoEnabled,
            'backup_max_keep'     => $maxKeep,
        ]);

        return Response::redirect($this->base() . '/admin/backup?success=settings_saved');
    }

    public function updatePage(Request $request): Response
    {

        $flash = $this->describeFlash((string) $request->query('success', ''));

        $updateInfo = $this->githubUpdate->checkLatestRelease();
        $releaseNotesCondensed = $updateInfo !== null
            ? $this->githubUpdate->condenseReleaseNotes((string) $updateInfo['release_notes'])
            : '';

        // Bundles installés que la release proposée ne chargerait plus (contrat qu'ils n'acceptent pas) : la page les
        // liste avant que l'Owner applique la mise à jour. Contrat illisible : pas d'avertissement possible.
        $targetContract = $updateInfo !== null && !empty($updateInfo['has_update'])
            ? $this->githubUpdate->contractVersionAt((string) $updateInfo['tag_name'])
            : null;
        $incompatibleBundles = $targetContract !== null
            ? (new BundleDiscoveryService())->installedIncompatibleWith($targetContract)
            : [];

        $currentChannel = $this->settings->updateChannel();
        $otherChannelsHistory = [];
        foreach (['release', 'beta', 'alpha'] as $channel) {
            if ($channel === $currentChannel) {
                continue;
            }
            $otherChannelsHistory[$channel] = $this->githubUpdate->getReleaseHistory($channel);
        }

        return Response::html($this->view->render('system.update', [
            'title'                     => 'Mises à jour',
            'currentVersion'            => $this->update->getCurrentVersion(),
            'updateInfo'                => $updateInfo,
            'updateCheckError'          => $this->githubUpdate->getLastCheckError(),
            'updateChannel'             => $currentChannel,
            'otherChannelsHistory'      => $otherChannelsHistory,
            'pendingMigs'               => $this->update->getPendingMigrations(),
            'releaseNotesCondensed'     => $releaseNotesCondensed,
            'repoReleasesUrl'           => $this->githubUpdate->getRepoReleasesUrl(),
            'flash'                     => $flash,
            'targetContract'            => $targetContract,
            'incompatibleBundles'       => $incompatibleBundles,
        ], 'layout.app'));
    }

    /** POST /admin/update/channel — change le canal de mise à jour suivi (release/beta/alpha). */
    public function saveChannel(Request $request): Response
    {

        $channel = (string) $request->post('channel', 'release');
        if (!in_array($channel, ['alpha', 'beta', 'release'], true)) {
            $channel = 'release';
        }

        $this->settings->setMany(['update_channel' => $channel]);

        $this->auditLogger->log($request, 'update.channel_changed', 'system', null, ['channel' => $channel]);

        return Response::redirect($this->base() . '/admin/update?success=channel_' . $channel);
    }

    /** POST /admin/update/apply — applique la dernière release GitHub disponible. */
    public function update(Request $request): Response
    {

        $wasMaintenanceEnabled = $this->settings->maintenanceModeEnabled();
        if (!$wasMaintenanceEnabled) {
            $this->settings->setMany(['maintenance_mode_enabled' => '1']);
        }

        try {
            try {
                $result = $this->githubUpdate->applyUpdate();
            } catch (\Throwable $e) {
                SessionFlash::put('danger', __('error_prefix') . $e->getMessage());
                return Response::redirect($this->base() . '/admin/update?success=error_failed');
            }

            if (!$result['ok']) {
                // applyUpdate() renvoie un code (no_update_available, download_failed…) traduit par describeFlash() ;
                // tout autre texte passe par la session, jamais par l'URL.
                $code = (string) $result['error'];
                if (preg_match('/^[a-z_]{1,60}$/', $code) === 1) {
                    return Response::redirect($this->base() . '/admin/update?success=error_' . $code);
                }
                SessionFlash::put('danger', __('error_prefix') . $code);
                return Response::redirect($this->base() . '/admin/update?success=error_failed');
            }

            $summary = sprintf(
                'updated_%s_files-%d_deleted-%d_migrations-%d_composer-%s',
                $result['version'],
                $result['files_copied'],
                $result['files_deleted'],
                $result['migrations_applied'],
                $result['composer'],
            );

            $this->auditLogger->log($request, 'update.applied', 'system', null, [
                'version'            => $result['version'],
                'files_copied'       => $result['files_copied'],
                'files_deleted'      => $result['files_deleted'],
                'migrations_applied' => $result['migrations_applied'],
            ]);

            return Response::redirect($this->base() . '/admin/update?success=' . urlencode($summary));
        } finally {
            if (!$wasMaintenanceEnabled) {
                $this->settings->setMany(['maintenance_mode_enabled' => '0']);
            }
        }
    }

    /**
     * POST /admin/update/stream — applique la dernière release GitHub
     * disponible en streamant la progression (SSE). Même contrat que
     * MessageStreamController::stream() : ne retourne jamais réellement,
     * termine par exit(0).
     */
    public function updateStream(Request $request): Response
    {

        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        set_time_limit(0);

        $emit = function (string $event, array $data): void {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data) . "\n\n";
            flush();
        };

        // exit() n'exécute pas les blocs finally en PHP : on restaure la maintenance
        // explicitement avant chaque exit() plutôt que de compter sur un try/finally.
        $wasMaintenanceEnabled = $this->settings->maintenanceModeEnabled();
        if (!$wasMaintenanceEnabled) {
            $this->settings->setMany(['maintenance_mode_enabled' => '1']);
        }
        $restoreMaintenance = function () use ($wasMaintenanceEnabled): void {
            if (!$wasMaintenanceEnabled) {
                $this->settings->setMany(['maintenance_mode_enabled' => '0']);
            }
        };

        try {
            $result = $this->githubUpdate->applyUpdate(function (int $percent, string $label) use ($emit): void {
                $emit('progress', ['percent' => $percent, 'label' => $label]);
            });
        } catch (\Throwable $e) {
            $restoreMaintenance();
            $emit('error', ['message' => $e->getMessage()]);
            exit(0);
        }

        if (!$result['ok']) {
            $restoreMaintenance();
            // Code connu (download_failed, checksum_failed…) : message traduit plutôt que le code brut.
            $code = (string) $result['error'];
            $text = __('backup_flash_error_' . $code);
            $emit('error', ['message' => $text !== 'backup_flash_error_' . $code ? $text : $code]);
            exit(0);
        }

        $this->auditLogger->log($request, 'update.applied', 'system', null, [
            'version'            => $result['version'] ?? null,
            'files_copied'       => $result['files_copied'] ?? null,
            'files_deleted'      => $result['files_deleted'] ?? null,
            'migrations_applied' => $result['migrations_applied'] ?? null,
        ]);

        $restoreMaintenance();
        $emit('done', $result);
        exit(0);
    }

    public function create(Request $request): Response
    {

        $note = trim((string) $request->post('note', ''));

        try {
            $result = $this->backup->create($note ?: null);
            $this->backup->enforceRetention($this->settings->backupMaxKeep());
            $this->auditLogger->log($request, 'backup.created', 'system', null, [
                'filename' => $result['filename'],
                'size'     => $result['size'],
                'source'   => 'manual',
            ]);
            return Response::redirect($this->base() . '/admin/backup?success=created_' . urlencode($result['filename']));
        } catch (\Throwable $e) {
            SessionFlash::put('danger', __('error_prefix') . $e->getMessage());
            return Response::redirect($this->base() . '/admin/backup?success=error_failed');
        }
    }

    public function restore(Request $request): Response
    {

        $filename = trim((string) $request->post('filename', ''));

        if ($filename === '') {
            return Response::redirect($this->base() . '/admin/backup?success=error_no_file');
        }

        try {
            $this->backup->restore($filename);
            $this->backup->enforceRetention($this->settings->backupMaxKeep());
            $this->auditLogger->log($request, 'backup.restored', 'system', null, ['filename' => $filename]);
            return Response::redirect($this->base() . '/admin/backup?success=restored');
        } catch (\Throwable $e) {
            SessionFlash::put('danger', __('error_prefix') . $e->getMessage());
            return Response::redirect($this->base() . '/admin/backup?success=error_failed');
        }
    }

    public function delete(Request $request): Response
    {

        $filename = trim((string) $request->post('filename', ''));

        if ($filename === '') {
            return Response::redirect($this->base() . '/admin/backup?success=error_no_file');
        }

        $this->backup->delete($filename);
        $this->auditLogger->log($request, 'backup.deleted', 'system', null, ['filename' => $filename]);
        return Response::redirect($this->base() . '/admin/backup?success=deleted');
    }

    public function migrate(Request $request): Response
    {

        $wasMaintenanceEnabled = $this->settings->maintenanceModeEnabled();
        if (!$wasMaintenanceEnabled) {
            $this->settings->setMany(['maintenance_mode_enabled' => '1']);
        }

        try {
            try {
                $count = $this->migrator->run();
                $this->auditLogger->log($request, 'system.migration_applied', 'system', null, ['migrations_applied' => $count]);
                return Response::redirect($this->base() . '/admin/update?success=migrated');
            } catch (\Throwable $e) {
                $this->auditLogger->log($request, 'db.migration_failed', 'system', null, ['error' => $e->getMessage()]);
                SessionFlash::put('danger', __('error_prefix') . $e->getMessage());
                return Response::redirect($this->base() . '/admin/update?success=error_failed');
            }
        } finally {
            if (!$wasMaintenanceEnabled) {
                $this->settings->setMany(['maintenance_mode_enabled' => '0']);
            }
        }
    }

    public function deleteAll(Request $request): Response
    {

        $count = $this->backup->deleteAll();
        $this->auditLogger->log($request, 'backup.deleted_all', 'system', null, ['count' => $count]);
        return Response::redirect($this->base() . '/admin/backup?success=deleted_all_' . $count);
    }

    /**
     * Traduit le code brut de ?success=... (voir les redirections de ce contrôleur)
     * en message affichable, stylé succès/erreur. Sans ça, la vue affichait le code
     * tel quel (ex. "error_SQLSTATE[...]...") dans une boîte neutre non stylée en
     * erreur — voir CHANGELOG. Les tests existants continuent d'asserter sur le code
     * brut dans l'URL de redirection : seul ce parsing, côté affichage, change.
     *
     * @return array{type: 'success'|'danger', text: string}|null
     */
    private function describeFlash(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $decoded = urldecode($raw);

        return match (true) {
            $decoded === 'settings_saved' => ['type' => 'success', 'text' => __('save_success')],
            $decoded === 'restored' => ['type' => 'success', 'text' => __('backup_flash_restored')],
            $decoded === 'deleted'  => ['type' => 'success', 'text' => __('backup_flash_deleted')],
            $decoded === 'migrated' => ['type' => 'success', 'text' => __('backup_flash_migrated')],
            str_starts_with($decoded, 'created_') && preg_match('/^created_[\w.-]{1,120}$/', $decoded) === 1 => ['type' => 'success', 'text' => __('backup_flash_created', [
                'filename' => substr($decoded, strlen('created_')),
            ])],
            preg_match('/^deleted_all_\d{1,6}$/', $decoded) === 1 => ['type' => 'success', 'text' => __('backup_flash_deleted_all', [
                'count' => substr($decoded, strlen('deleted_all_')),
            ])],
            preg_match('/^channel_(release|beta|alpha)$/', $decoded) === 1 => ['type' => 'success', 'text' => __('backup_flash_channel', [
                'channel' => substr($decoded, strlen('channel_')),
            ])],
            str_starts_with($decoded, 'updated_') && preg_match(
                '/^updated_(?<version>\d+\.\d+\.\d+)_files-(?<copied>\d+)_deleted-(?<deleted>\d+)_migrations-(?<migrations>\d+)_composer-/',
                $decoded,
                $m
            ) === 1 => ['type' => 'success', 'text' => strtr(__('backup_update_done_summary'), [
                '{version}'    => $m['version'],
                '{copied}'     => $m['copied'],
                '{deleted}'    => $m['deleted'],
                '{migrations}' => $m['migrations'],
            ])],
            $decoded === 'error_no_file'            => ['type' => 'danger', 'text' => __('backup_flash_error_no_file')],
            $decoded === 'error_not_found'           => ['type' => 'danger', 'text' => __('backup_flash_error_not_found')],
            $decoded === 'error_no_update_available' => ['type' => 'danger', 'text' => __('backup_flash_error_no_update_available')],
            $decoded === 'error_download_failed'     => ['type' => 'danger', 'text' => __('backup_flash_error_download_failed')],
            $decoded === 'error_extract_failed'      => ['type' => 'danger', 'text' => __('backup_flash_error_extract_failed')],
            $decoded === 'error_release_archive_missing' => ['type' => 'danger', 'text' => __('backup_flash_error_release_archive_missing')],
            $decoded === 'error_checksum_failed'     => ['type' => 'danger', 'text' => __('backup_flash_error_checksum_failed')],
            $decoded === 'error_commit_mismatch'     => ['type' => 'danger', 'text' => __('backup_flash_error_commit_mismatch')],
            // Le détail de l'échec est déjà affiché par le layout depuis la session : pas de second bandeau.
            $decoded === 'error_failed' => null,
            // Jamais le texte brut de l'URL (n'importe qui pourrait fabriquer le lien) : le détail d'une erreur
            // passe par kintai\Core\SessionFlash, affiché par le layout.
            str_starts_with($decoded, 'error_') => ['type' => 'danger', 'text' => __('error_generic')],
            default => null,
        };
    }
}
