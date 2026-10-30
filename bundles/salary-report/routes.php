<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\SalaryReport\Controllers\Web\AdminSalaryReportController;


// =============================================================================
// SalaryReport — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/reports/salary', [AdminSalaryReportController::class, 'allSalaryReports'])->name('admin.reports.salary')->permission('payroll.view');
    Route::get('/reports/salary/export/json', [AdminSalaryReportController::class, 'exportSalaryReportsJson'])->name('admin.reports.salary.export_json')->permission('payroll.view');
    Route::get('/reports/salary/export/pdf', [AdminSalaryReportController::class, 'exportSalaryReportsPdf'])->name('admin.reports.salary.export_pdf')->permission('payroll.view');
    Route::get('/reports/salary/export/pdf/download', [AdminSalaryReportController::class, 'exportSalaryReportsPdfDownload'])->name('admin.reports.salary.export_pdf_download')->permission('payroll.view');

    Route::get('/stores/{id}/reports/salary', [AdminSalaryReportController::class, 'salaryReports'])->name('admin.stores.salary_reports')->permission('payroll.view');
    Route::get('/stores/{id}/reports/salary/create', [AdminSalaryReportController::class, 'createSalaryReport'])->name('admin.stores.salary_reports.create')->permission('payroll.generate');
    Route::post('/stores/{id}/reports/salary/create', [AdminSalaryReportController::class, 'storeSalaryReport'])->name('admin.stores.salary_reports.store')->permission('payroll.generate');
    Route::get('/stores/{id}/reports/salary/calculate', [AdminSalaryReportController::class, 'calculateSalaryReport'])->name('admin.stores.salary_reports.calculate')->permission('payroll.generate');
    Route::get('/stores/{id}/reports/salary/{rid}', [AdminSalaryReportController::class, 'showSalaryReport'])->name('admin.stores.salary_reports.show')->permission('payroll.view');
    Route::get('/stores/{id}/reports/salary/{rid}/edit', [AdminSalaryReportController::class, 'editSalaryReport'])->name('admin.stores.salary_reports.edit')->permission('payroll.view');
    Route::post('/stores/{id}/reports/salary/{rid}/edit', [AdminSalaryReportController::class, 'updateSalaryReport'])->name('admin.stores.salary_reports.update')->permission('payroll.generate');
    Route::post('/stores/{id}/reports/salary/{rid}/delete', [AdminSalaryReportController::class, 'deleteSalaryReport'])->name('admin.stores.salary_reports.delete')->permission('payroll.generate');
    Route::get('/stores/{id}/reports/salary/{rid}/pdf', [AdminSalaryReportController::class, 'salaryReportPdf'])->name('admin.stores.salary_reports.pdf')->permission('payroll.export');
    Route::get('/stores/{id}/reports/salary/{rid}/pdf/download', [AdminSalaryReportController::class, 'salaryReportPdfDownload'])->name('admin.stores.salary_reports.pdf_download')->permission('payroll.export');
});
