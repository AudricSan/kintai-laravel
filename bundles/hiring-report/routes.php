<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\HiringReport\Controllers\Web\AdminHiringReportController;


// =============================================================================
// HiringReport — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/reports/hiring', [AdminHiringReportController::class, 'allHiringReports'])->name('admin.reports.hiring')->permission('hiring_reports.view');

    Route::get('/stores/{id}/reports/hiring', [AdminHiringReportController::class, 'hiringReports'])->name('admin.stores.hiring_reports')->permission('hiring_reports.view');
    Route::get('/stores/{id}/reports/hiring/create', [AdminHiringReportController::class, 'createHiringReport'])->name('admin.stores.hiring_reports.create')->permission('hiring_reports.create');
    Route::post('/stores/{id}/reports/hiring/create', [AdminHiringReportController::class, 'storeHiringReport'])->name('admin.stores.hiring_reports.store')->permission('hiring_reports.create');
    Route::get('/stores/{id}/reports/hiring/{rid}', [AdminHiringReportController::class, 'showHiringReport'])->name('admin.stores.hiring_reports.show')->permission('hiring_reports.view');
    Route::get('/stores/{id}/reports/hiring/{rid}/edit', [AdminHiringReportController::class, 'editHiringReport'])->name('admin.stores.hiring_reports.edit')->permission('hiring_reports.view');
    Route::post('/stores/{id}/reports/hiring/{rid}/edit', [AdminHiringReportController::class, 'updateHiringReport'])->name('admin.stores.hiring_reports.update')->permission('hiring_reports.update');
    Route::post('/stores/{id}/reports/hiring/{rid}/delete', [AdminHiringReportController::class, 'deleteHiringReport'])->name('admin.stores.hiring_reports.delete')->permission('hiring_reports.delete');
    Route::get('/stores/{id}/reports/hiring/{rid}/pdf', [AdminHiringReportController::class, 'hiringReportPdf'])->name('admin.stores.hiring_reports.pdf')->permission('hiring_reports.view');
    Route::get('/stores/{id}/reports/hiring/{rid}/pdf/download', [AdminHiringReportController::class, 'hiringReportPdfDownload'])->name('admin.stores.hiring_reports.pdf_download')->permission('hiring_reports.view');
});
