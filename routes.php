<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\ResignationReport\Controllers\Web\AdminResignationReportController;


// =============================================================================
// ResignationReport — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/reports/resignation', [AdminResignationReportController::class, 'allResignationReports'])->name('admin.reports.resignation')->permission('resignation_reports.view');
    Route::get('/reports/resignation/export/json', [AdminResignationReportController::class, 'exportResignationReportsJson'])->name('admin.reports.resignation.export_json')->permission('resignation_reports.view');
    Route::get('/reports/resignation/export/pdf', [AdminResignationReportController::class, 'exportResignationReportsPdf'])->name('admin.reports.resignation.export_pdf')->permission('resignation_reports.view');
    Route::get('/reports/resignation/export/pdf/download', [AdminResignationReportController::class, 'exportResignationReportsPdfDownload'])->name('admin.reports.resignation.export_pdf_download')->permission('resignation_reports.view');

    Route::get('/stores/{id}/reports/resignation', [AdminResignationReportController::class, 'resignationReports'])->name('admin.stores.resignation_reports')->permission('resignation_reports.view');
    Route::get('/stores/{id}/reports/resignation/create', [AdminResignationReportController::class, 'createResignationReport'])->name('admin.stores.resignation_reports.create')->permission('resignation_reports.create');
    Route::post('/stores/{id}/reports/resignation/create', [AdminResignationReportController::class, 'storeResignationReport'])->name('admin.stores.resignation_reports.store')->permission('resignation_reports.create');
    Route::get('/stores/{id}/reports/resignation/{rid}', [AdminResignationReportController::class, 'showResignationReport'])->name('admin.stores.resignation_reports.show')->permission('resignation_reports.view');
    Route::get('/stores/{id}/reports/resignation/{rid}/edit', [AdminResignationReportController::class, 'editResignationReport'])->name('admin.stores.resignation_reports.edit')->permission('resignation_reports.view');
    Route::post('/stores/{id}/reports/resignation/{rid}/edit', [AdminResignationReportController::class, 'updateResignationReport'])->name('admin.stores.resignation_reports.update')->permission('resignation_reports.update');
    Route::post('/stores/{id}/reports/resignation/{rid}/delete', [AdminResignationReportController::class, 'deleteResignationReport'])->name('admin.stores.resignation_reports.delete')->permission('resignation_reports.delete');
    Route::post('/stores/{id}/reports/resignation/{rid}/delete-permanently', [AdminResignationReportController::class, 'deleteResignationReportPermanently'])->name('admin.stores.resignation_reports.delete_permanently')->permission('resignation_reports.delete');
    Route::get('/stores/{id}/reports/resignation/{rid}/pdf', [AdminResignationReportController::class, 'resignationReportPdf'])->name('admin.stores.resignation_reports.pdf')->permission('resignation_reports.view');
    Route::get('/stores/{id}/reports/resignation/{rid}/pdf/download', [AdminResignationReportController::class, 'resignationReportPdfDownload'])->name('admin.stores.resignation_reports.pdf_download')->permission('resignation_reports.view');
    Route::post('/stores/{id}/reports/resignation/{rid}/reactivate', [AdminResignationReportController::class, 'reactivateUser'])->name('admin.stores.resignation_reports.reactivate')->permission('employees.update');
});
