<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\Notebook\Controllers\Web\NotebookController;
use kintai\Bundles\Installed\Notebook\Controllers\Api\NotebookController as ApiNotebookController;


// =============================================================================
// Notebook — Routes Web
// =============================================================================
// Un seul groupe : l'accès est purement RBAC (notebook.view/create/manage),
// pas basé sur le rôle admin/employé — contrairement à Feedback/Messaging qui
// séparent /admin et /employee, la lecture et l'écriture d'une note sont
// accessibles à qui détient la permission, où qu'il/elle soit dans l'app.

Route::prefix('notebook')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('', [NotebookController::class, 'index'])->name('notebook.index')->permission('notebook.view');
    Route::post('', [NotebookController::class, 'store'])->name('notebook.store')->permission('notebook.create');
    Route::post('/{id}/update', [NotebookController::class, 'update'])->name('notebook.update')->permission('notebook.create');
    Route::post('/{id}/delete', [NotebookController::class, 'destroy'])->name('notebook.delete')->permission('notebook.create');
    Route::post('/{id}/pin', [NotebookController::class, 'togglePin'])->name('notebook.pin')->permission('notebook.manage');
});

// =============================================================================
// Notebook — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {
    Route::get('/notebook-entries', [ApiNotebookController::class, 'index'])->name('api.v1.notebook_entries.index')->permission('notebook.view');
    Route::post('/notebook-entries', [ApiNotebookController::class, 'store'])->name('api.v1.notebook_entries.store')->permission('notebook.create');
    Route::get('/notebook-entries/{id}', [ApiNotebookController::class, 'show'])->name('api.v1.notebook_entries.show')->permission('notebook.view');
    Route::put('/notebook-entries/{id}', [ApiNotebookController::class, 'update'])->name('api.v1.notebook_entries.update')->permission('notebook.create');
    Route::delete('/notebook-entries/{id}', [ApiNotebookController::class, 'destroy'])->name('api.v1.notebook_entries.destroy')->permission('notebook.create');
});
