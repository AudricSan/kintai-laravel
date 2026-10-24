<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\BundleManager;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Sert les assets statiques (CSS/JS) qu'un bundle actif a déclarés via
 * Bundle::loadAssetsFrom(). Contrairement à StorageFileController
 * (storage/uploads, fichiers utilisateur, authentifié), ces fichiers sont
 * publics par nature — pas d'authentification, mais même discipline de
 * confinement realpath() + whitelist d'extensions pour éviter tout
 * directory traversal ou service de fichier arbitraire.
 */
final class BundleAssetController
{
    /** @var array<string, string> extension → type MIME */
    private const MIME_TYPES = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(private readonly BundleManager $bundleManager)
    {
    }

    public function serve(Request $request): Response
    {
        $slug = (string) $request->param('slug');
        $path = (string) $request->param('path');

        $root = $this->bundleManager->assetsPathFor($slug);
        if ($root === null) {
            throw new NotFoundException(__('error_file_not_found'));
        }

        $base = realpath($root);
        if ($base === false) {
            throw new NotFoundException(__('error_file_not_found'));
        }

        $file = realpath($base . DIRECTORY_SEPARATOR . $path);
        if ($file === false || !is_file($file)) {
            throw new NotFoundException(__('error_file_not_found'));
        }

        // Confinement : le chemin résolu doit rester sous le dossier d'assets du bundle
        if (!str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new ForbiddenException(__('error_path_not_allowed'));
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$ext])) {
            throw new ForbiddenException(__('error_file_type_not_allowed'));
        }

        $name = basename($file);

        return Response::fileStream($file, self::MIME_TYPES[$ext], $name)
            ->withHeader('Content-Disposition', "inline; filename=\"{$name}\"")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
