<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\PlanningCheck\Http\CheckController;

// Préfixe `api/v1/planning-check`, noms `api.v1.planning-check.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits sont ceux de l'écran (PlanningCheckAccess).
Route::get('tomorrow', [CheckController::class, 'tomorrow'])->name('tomorrow');
Route::post('submit', [CheckController::class, 'submit'])->name('submit');
Route::post('send-now', [CheckController::class, 'sendNow'])->name('send-now');

Route::prefix('checks')->name('checks.')->whereNumber('check')->group(function (): void {
    Route::get('/', [CheckController::class, 'index'])->name('index');
    Route::get('{check}', [CheckController::class, 'show'])->name('show');
    Route::post('{check}/approve', [CheckController::class, 'approve'])->name('approve');
    Route::post('{check}/reject', [CheckController::class, 'reject'])->name('reject');
    Route::post('{check}/resend', [CheckController::class, 'resend'])->name('resend');
    Route::post('{check}/reopen', [CheckController::class, 'reopen'])->name('reopen');
});
