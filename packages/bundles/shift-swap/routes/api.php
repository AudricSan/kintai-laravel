<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\ShiftSwap\Http\SwapController;

// Préfixe `api/v1/shift-swap`, noms `api.v1.shift-swap.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits sont ceux des écrans (SwapAccess) : chacun voit et traite ses demandes, les gérants celles de leurs magasins.
Route::prefix('requests')->name('requests.')->whereNumber('swap')->group(function (): void {
    Route::get('/', [SwapController::class, 'index'])->name('index');
    Route::post('/', [SwapController::class, 'store'])->name('store');
    Route::post('apply', [SwapController::class, 'apply'])->name('apply');
    Route::get('{swap}', [SwapController::class, 'show'])->name('show');
    Route::put('{swap}', [SwapController::class, 'update'])->name('update');
    Route::delete('{swap}', [SwapController::class, 'destroy'])->name('destroy');
    Route::post('{swap}/accept', [SwapController::class, 'accept'])->name('accept');
    Route::post('{swap}/decline', [SwapController::class, 'decline'])->name('decline');
    Route::post('{swap}/cancel', [SwapController::class, 'cancel'])->name('cancel');
    Route::post('{swap}/approve', [SwapController::class, 'approve'])->name('approve');
    Route::post('{swap}/refuse', [SwapController::class, 'refuse'])->name('refuse');
});
