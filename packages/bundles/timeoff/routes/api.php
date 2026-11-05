<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\Timeoff\Http\RequestController;

// Préfixe `api/v1/timeoff`, noms `api.v1.timeoff.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits sont ceux des écrans (TimeoffAccess) : chacun voit et gère ses demandes, les gérants celles de leurs magasins.
Route::prefix('requests')->name('requests.')->whereNumber('timeoff')->group(function (): void {
    Route::get('/', [RequestController::class, 'index'])->name('index');
    Route::post('/', [RequestController::class, 'store'])->name('store');
    Route::get('{timeoff}', [RequestController::class, 'show'])->name('show');
    Route::put('{timeoff}', [RequestController::class, 'update'])->name('update');
    Route::delete('{timeoff}', [RequestController::class, 'destroy'])->name('destroy');
    Route::post('{timeoff}/approve', [RequestController::class, 'approve'])->name('approve');
    Route::post('{timeoff}/refuse', [RequestController::class, 'refuse'])->name('refuse');
    Route::post('{timeoff}/cancel', [RequestController::class, 'cancel'])->name('cancel');
});
