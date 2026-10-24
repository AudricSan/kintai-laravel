<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Sert les fichiers de storage/uploads derrière l'authentification.
 *
 * Remplace l'ancien public/serve-storage.php qui exposait les uploads
 * sans authentification et avec une protection anti-traversal fragile.
 *
 * - Chemin résolu via realpath() et confiné à storage/uploads.
 * - Extensions limitées à une liste blanche.
 * - Les images sont servies inline ; tout le reste (SVG compris, pour
 *   neutraliser le XSS stocké) est envoyé en pièce jointe.
 * - Les managers de store n'accèdent qu'aux fichiers de leurs stores
 *   (préfixes img/{storeId}/ et {storeId}/).
 */
final class StorageFileController
{
    use HasAdminAccess;

    /** @var array<string, string> extension → type MIME */
    private const INLINE_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    /** @var array<string, string> extensions servies uniquement en téléchargement */
    private const ATTACHMENT_TYPES = [
        'svg'  => 'image/svg+xml',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv',
        'zip'  => 'application/zip',
    ];

    private readonly string $uploadsPath;

    public function __construct(?string $uploadsPath = null)
    {
        $this->uploadsPath = $uploadsPath ?? BASE_PATH . '/storage/uploads';
    }

    public function serve(Request $request): Response
    {
        $path = (string) $request->param('path');

        $base = realpath($this->uploadsPath);
        if ($base === false) {
            throw new NotFoundException(__('error_storage_not_found'));
        }

        $file = realpath($base . DIRECTORY_SEPARATOR . $path);
        if ($file === false || !is_file($file)) {
            throw new NotFoundException(__('error_file_not_found'));
        }

        // Confinement : le chemin résolu doit rester sous storage/uploads
        if (!str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new ForbiddenException(__('error_path_not_allowed'));
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $inline = isset(self::INLINE_TYPES[$ext]);
        if (!$inline && !isset(self::ATTACHMENT_TYPES[$ext])) {
            throw new ForbiddenException(__('error_file_type_not_allowed'));
        }

        $this->assertPathStoreAccess($request, $file, $base);

        $mime = self::INLINE_TYPES[$ext] ?? self::ATTACHMENT_TYPES[$ext];
        $name = basename($file);

        $response = Response::fileStream($file, $mime, $name)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=3600');

        if ($inline) {
            $response = $response->withHeader('Content-Disposition', "inline; filename=\"{$name}\"");
        }

        return $response;
    }

    /**
     * Restreint les managers non-globaux aux fichiers de leurs stores.
     * Arborescences connues : img/{storeId}/... (photos) et {storeId}/... (imports).
     */
    private function assertPathStoreAccess(Request $request, string $file, string $base): void
    {
        $managedIds = $this->managedIds($request);
        if ($managedIds === null) {
            return; // admin global
        }

        $relative = str_replace('\\', '/', substr($file, strlen($base) + 1));
        $segments = explode('/', $relative);

        $storeSegment = $segments[0] === 'img' ? ($segments[1] ?? '') : $segments[0];
        if (!ctype_digit($storeSegment) || !in_array((int) $storeSegment, $managedIds, true)) {
            throw new ForbiddenException(__('error_not_store_manager'));
        }
    }
}
