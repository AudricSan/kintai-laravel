<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PwaController
{
    public function manifest(Request $request): Response
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

        $data = [
            'name'             => 'Kintai',
            'short_name'       => 'Kintai',
            'description'      => 'Gestion de plannings et pointages',
            'start_url'        => $base . '/',
            'scope'            => $base . '/',
            'display'          => 'standalone',
            'background_color' => '#f5f5f5',
            'theme_color'      => '#1a5c8c',
            'icons'            => [
                [
                    'src'     => $base . '/assets/img/kintai-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => $base . '/assets/img/kintai-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => $base . '/assets/img/kintai-512-maskable.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response()->json($data)->header('Content-Type', 'application/manifest+json');
    }

    /**
     * Sert public/sw.js à partir d'un template : contrairement aux autres
     * fichiers sous public/, il doit passer par PHP pour recevoir la même
     * valeur de cache-busting que asset_version() (le nom de son cache
     * `CACHE`, sans quoi il faudrait le bumper à la main à chaque changement
     * sous public/assets/css|js — voir asset_version() pour le détail).
     */
    public function serviceWorker(Request $request): Response
    {
        $template = file_get_contents(BASE_PATH . '/resources/templates/sw.js.tpl');
        $body = str_replace('__ASSET_VERSION__', asset_version(), $template);

        return response($body)->header('Content-Type', 'application/javascript; charset=UTF-8');
    }
}
