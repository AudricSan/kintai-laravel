<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web\System;

use kintai\Core\Auth\AuthService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppResetService;
use kintai\Core\Services\BackupService;
use kintai\UI\Controller\Web\HasBaseUrl;
use kintai\UI\ViewRenderer;

/**
 * Réinitialisation de l'application depuis /admin/backup ("Danger zone", Owner uniquement).
 * Flux en 2 étapes pour garantir qu'une sauvegarde est créée ET son téléchargement
 * proposé AVANT toute action irréversible : prepare() sauvegarde et affiche une page
 * de confirmation, execute() applique réellement la réinitialisation.
 */
final class AppResetController
{
    use HasBaseUrl;

    private const MODES = ['data', 'factory'];

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly BackupService $backup,
        private readonly AppResetService $reset,
        private readonly AuthService $auth,
    ) {}

    /** POST /admin/reset/prepare */
    public function prepare(Request $request): Response
    {

        $mode = (string) $request->post('mode', 'data');
        if (!in_array($mode, self::MODES, true)) {
            return Response::redirect($this->base() . '/admin/backup?error=invalid_reset_mode');
        }
        $keep = $this->validKeepCategories($request->post('keep', []));

        $backup = $this->backup->create('[auto] Sauvegarde avant réinitialisation (' . $mode . ')');

        return Response::html($this->view->render('system.reset-confirm', [
            'title'          => __('reset_title'),
            'mode'           => $mode,
            'keep'           => $keep,
            'backup_filename' => $backup['filename'],
            'download_url'   => $this->base() . '/admin/backup/download?filename=' . urlencode($backup['filename']),
        ], 'layout.app'));
    }

    /** POST /admin/reset/execute */
    public function execute(Request $request): Response
    {

        $mode = (string) $request->post('mode', 'data');
        if (!in_array($mode, self::MODES, true)) {
            return Response::redirect($this->base() . '/admin/backup?error=invalid_reset_mode');
        }
        $keep = $this->validKeepCategories($request->post('keep', []));

        $backupFilename = (string) $request->post('backup_filename', '');
        if ($backupFilename === '' || !file_exists($this->backup->getPath($backupFilename))) {
            return Response::redirect($this->base() . '/admin/backup?error=reset_backup_missing');
        }

        $user = $request->getAttribute('auth_user') ?? [];
        $this->logReset($mode, $keep, $backupFilename, (string) ($user['email'] ?? $user['id'] ?? 'unknown'));

        if ($mode === 'factory') {
            $this->reset->resetFactory();
            $this->auth->logout();
            return Response::redirect($this->base() . '/');
        }

        $this->reset->resetData($keep);
        return Response::redirect($this->base() . '/admin/backup?success=reset_data');
    }

    /** @return string[] */
    private function validKeepCategories(mixed $keep): array
    {
        if (!is_array($keep)) {
            return [];
        }
        return array_values(array_intersect($keep, array_keys(AppResetService::OPTIONAL_CATEGORIES)));
    }

    /**
     * Trace persistante hors base (la base elle-même vient d'être vidée) de qui a
     * déclenché une réinitialisation, quand, et sous quel mode.
     */
    private function logReset(string $mode, array $keep, string $backupFilename, string $actor): void
    {
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $entry = sprintf(
            "[%s] Réinitialisation (%s) par %s — backup: %s — conservé: %s\n",
            date('Y-m-d H:i:s'),
            $mode,
            $actor,
            $backupFilename,
            $keep === [] ? '(rien)' : implode(',', $keep)
        );
        @file_put_contents($logDir . '/reset.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
