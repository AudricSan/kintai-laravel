<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\Timeclock\Http\EntryController;

// Préfixe `api/v1/timeclock`, noms `api.v1.timeclock.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Chacun pointe et lit ses pointages ; les gérants voient, corrigent et suppriment ceux de leurs magasins (TimeclockAccess).
Route::post('clock-in', [EntryController::class, 'clockIn'])->name('clock_in');
Route::post('clock-out', [EntryController::class, 'clockOut'])->name('clock_out');
Route::get('active', [EntryController::class, 'active'])->name('active');

Route::prefix('entries')->name('entries.')->whereNumber('entry')->group(function (): void {
    Route::get('/', [EntryController::class, 'index'])->name('index');
    Route::get('{entry}', [EntryController::class, 'show'])->name('show');
    Route::put('{entry}', [EntryController::class, 'update'])->name('update');
    Route::delete('{entry}', [EntryController::class, 'destroy'])->name('destroy');
});
