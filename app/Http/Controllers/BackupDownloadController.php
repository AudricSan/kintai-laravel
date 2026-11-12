<?php

namespace App\Http\Controllers;

use App\Activity\ActivityLogger;
use App\Backups\BackupManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'une sauvegarde (Owner seulement). L'archive vit hors du dossier public : elle n'est jamais servie
 * directement. L'identifiant de la route (motif strict) est cherché dans la liste réelle du dossier ; il ne désigne jamais
 * un chemin, et c'est lui, déjà sûr, qui donne le nom du fichier téléchargé.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, string $backup, BackupManager $manager, ActivityLogger $logger): StreamedResponse
    {
        Gate::authorize('manage-settings');

        $info = $manager->find($backup);
        abort_if($info === null, 404);

        $path = $manager->managedPath($info);

        $logger->record('backup.downloaded', null, null, ['backup' => $info->id]);

        return response()->streamDownload(function () use ($path): void {
            $handle = fopen($path, 'rb');

            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }
        }, $info->id, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $info->size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
