<?php

namespace App\Http\Middleware;

use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une fois Kintai installé, l'assistant `/install` n'existe plus : 404.
 */
class EnsureNotInstalled
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->installer->isInstalled(), 404);

        return $next($request);
    }
}
