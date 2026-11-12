<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\Notebook\Http\EntryController;

// Préfixe `api/v1/notebook`, noms `api.v1.notebook.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits par magasin sont ceux de la page (NotebookAccess).
Route::prefix('entries')->name('entries.')->whereNumber('entry')->group(function (): void {
    Route::get('/', [EntryController::class, 'index'])->name('index');
    Route::post('/', [EntryController::class, 'store'])->name('store');
    Route::get('{entry}', [EntryController::class, 'show'])->name('show');
    Route::put('{entry}', [EntryController::class, 'update'])->name('update');
    Route::delete('{entry}', [EntryController::class, 'destroy'])->name('destroy');
    Route::post('{entry}/pin', [EntryController::class, 'pin'])->name('pin');
});
