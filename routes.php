<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Bundles\Installed\TeamDirectory\Controllers\Web\TeamDirectoryController;


// =============================================================================
// Team Directory — Routes Web (tout utilisateur connecté, pas de RBAC :
// libre-service au même titre que /profile)
// =============================================================================

Route::prefix('team')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('', [TeamDirectoryController::class, 'index'])->name('team.index');
    Route::get('/{id}', [TeamDirectoryController::class, 'show'])->name('team.show');
});
