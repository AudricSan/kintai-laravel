<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Http\KintaiBridge;
use kintai\Http\KintaiHttp;
use kintai\Http\KintaiRoutes;

// Routes de Kintai (routes/kintai.php) et de ses bundles, déclarées au routeur de Laravel dans leur ordre.
$kintaiMiddleware = [];
if (KintaiHttp::shouldRegisterRoutes(app())) {
    $kintai = KintaiHttp::app(app());
    KintaiRoutes::register($kintai->router(), app('router'), $kintai->globalMiddleware());
    // Même pile que les routes Kintai : ResolveKintaiRoute y laisse le routeur Kintai décider (404, 405 ou sa route).
    $kintaiMiddleware = KintaiRoutes::middleware($kintai->globalMiddleware());
}

// Aucune route ne correspond : Kintai rend sa page 404, ou 405 si le chemin existe pour une autre méthode.
Route::any('/{path?}', KintaiBridge::class)->where('path', '.*')->middleware($kintaiMiddleware);
