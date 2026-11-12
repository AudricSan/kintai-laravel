<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\DailyReport\Http\ReportController;

// Préfixe `api/v1/daily-report`, noms `api.v1.daily-report.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits sont ceux des écrans (DailyReportAccess).
Route::prefix('reports')->name('reports.')->whereNumber('report')->group(function (): void {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::post('/', [ReportController::class, 'store'])->name('store');
    Route::get('{report}', [ReportController::class, 'show'])->name('show');
    Route::put('{report}', [ReportController::class, 'update'])->name('update');
    Route::delete('{report}', [ReportController::class, 'destroy'])->name('destroy');
    Route::post('{report}/submit', [ReportController::class, 'submit'])->name('submit');
    Route::post('{report}/validate', [ReportController::class, 'validateReport'])->name('validate');
    Route::post('{report}/send-mail', [ReportController::class, 'sendMail'])->name('send-mail');
});
