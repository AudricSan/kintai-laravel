<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\SalaryReport\Http\ReportController;

// Préfixe `api/v1/salary-report`, noms `api.v1.salary-report.*`, pile de l'API du Core (jeton Sanctum, langue, limiteur, compte actif).
// Les droits sont ceux des écrans (SalaryReportAccess), un par action, évalués sur le magasin du rapport.
Route::prefix('reports')->name('reports.')->whereNumber('report')->group(function (): void {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::post('/', [ReportController::class, 'store'])->name('store');
    Route::get('calculate', [ReportController::class, 'calculate'])->name('calculate');
    Route::get('{report}', [ReportController::class, 'show'])->name('show');
    Route::put('{report}', [ReportController::class, 'update'])->name('update');
    Route::delete('{report}', [ReportController::class, 'destroy'])->name('destroy');
});
