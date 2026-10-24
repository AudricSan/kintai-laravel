<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Http\KintaiBridge;
use kintai\Http\KintaiHttp;
use kintai\Http\KintaiRoutes;

// Routes de Kintai (routes/kintai.php) et de ses bundles, déclarées au routeur de Laravel dans leur ordre.
if (KintaiHttp::shouldRegisterRoutes(app())) {
    KintaiRoutes::register(KintaiHttp::app(app())->router(), app('router'));
}

// Aucune route ne correspond : Kintai rend sa page 404, ou 405 si le chemin existe pour une autre méthode.
Route::any('/{path?}', KintaiBridge::class)->where('path', '.*');
