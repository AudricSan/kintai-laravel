<?php

namespace App\Http\Middleware;

use App\Install\Installer;
use App\Install\InstallFailure;
use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tant que Kintai n'est pas installé, toute page web renvoie vers l'assistant `/install`.
 *
 * Avant l'installation il n'y a peut-être ni `.env`, ni clé d'application, ni base : le middleware prépare `.env`,
 * assure une clé (nécessaire aux cookies chiffrés et au CSRF) et passe session et cache sur des fichiers, sans base.
 * Il passe en premier dans le groupe `web`, avant le chiffrement des cookies et le démarrage de la session.
 */
class RedirectToInstaller
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('kintai.install.enforce') || $this->installer->isInstalled()) {
            return $next($request);
        }

        try {
            $this->installer->prepareEnvironment();
        } catch (InstallFailure) {
            // `.env` non inscriptible : l'assistant l'indique dans les prérequis. Clé provisoire pour pouvoir l'afficher.
            if (config('app.key') === null || config('app.key') === '') {
                config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')))]);
            }
        }

        config(['session.driver' => 'file', 'cache.default' => 'file']);

        if ($request->routeIs('install.*')) {
            return $next($request);
        }

        return redirect()->route('install.show');
    }
}
