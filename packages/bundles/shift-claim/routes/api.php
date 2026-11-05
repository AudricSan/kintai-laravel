<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\ShiftClaim\Http\ClaimController;

// Préfixe `api/v1/shift-claim`, noms `api.v1.shift-claim.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits sont ceux des écrans (ClaimAccess) : chacun candidate et suit ses candidatures, les gérants celles de leurs magasins.
Route::get('open-shifts', [ClaimController::class, 'openShifts'])->name('open-shifts.index');
Route::post('open-shifts/{shift}/claim', [ClaimController::class, 'claim'])->whereNumber('shift')->name('open-shifts.claim');
Route::post('shifts/{shift}/offer', [ClaimController::class, 'offer'])->whereNumber('shift')->name('shifts.offer');
Route::delete('shifts/{shift}/offer', [ClaimController::class, 'unoffer'])->whereNumber('shift')->name('shifts.unoffer');

Route::prefix('claims')->name('claims.')->whereNumber('claim')->group(function (): void {
    Route::get('/', [ClaimController::class, 'index'])->name('index');
    Route::get('{claim}', [ClaimController::class, 'show'])->name('show');
    Route::post('{claim}/withdraw', [ClaimController::class, 'withdraw'])->name('withdraw');
    Route::post('{claim}/approve', [ClaimController::class, 'approve'])->name('approve');
    Route::post('{claim}/reject', [ClaimController::class, 'reject'])->name('reject');
});
