<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\BundleManager;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $slug = (string) $request->route('slug');
        $path = (string) $request->route('path');

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

        return response()->fileStream($file, self::MIME_TYPES[$ext], $name)
            ->header('Content-Disposition', "inline; filename=\"{$name}\"")
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
