<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\ApiAuthMiddleware;
use App\Http\Middleware\ApiPermissionMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\DailyReport\Controllers\Web\DailyReportController;
use kintai\Bundles\Installed\DailyReport\Controllers\Api\DailyReportController as ApiDailyReportController;


// =============================================================================
// Daily Report — Routes Web (admin)
// =============================================================================

Route::get('/admin/daily-reports', [DailyReportController::class, 'indexAll'])->middleware([AuthMiddleware::class, PermissionMiddleware::class])->name('admin.daily_reports.all')->permission('daily_reports.view');

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/stores/{id}/daily-reports', [DailyReportController::class, 'index'])->name('admin.daily_reports.index')->permission(['perm' => 'daily_reports.view', 'membership' => true]);
    Route::get('/stores/{id}/daily-reports/settings', [DailyReportController::class, 'editSettings'])->name('admin.daily_reports.settings')->permission('daily_reports.update');
    Route::post('/stores/{id}/daily-reports/settings', [DailyReportController::class, 'saveSettings'])->name('admin.daily_reports.settings.save')->permission('daily_reports.update');
    Route::get('/stores/{id}/daily-reports/create', [DailyReportController::class, 'create'])->name('admin.daily_reports.create')->permission(['perm' => 'daily_reports.create', 'membership' => true]);
    Route::post('/stores/{id}/daily-reports/create', [DailyReportController::class, 'store'])->name('admin.daily_reports.store')->permission(['perm' => 'daily_reports.create', 'membership' => true]);
    Route::get('/stores/{id}/daily-reports/{rid}', [DailyReportController::class, 'show'])->name('admin.daily_reports.show')->permission(['perm' => 'daily_reports.view', 'membership' => true]);
    Route::get('/stores/{id}/daily-reports/{rid}/edit', [DailyReportController::class, 'edit'])->name('admin.daily_reports.edit')->permission(['perm' => 'daily_reports.update', 'membership' => true]);
    Route::post('/stores/{id}/daily-reports/{rid}/edit', [DailyReportController::class, 'update'])->name('admin.daily_reports.update')->permission(['perm' => 'daily_reports.update', 'membership' => true]);
    Route::post('/stores/{id}/daily-reports/{rid}/submit', [DailyReportController::class, 'submit'])->name('admin.daily_reports.submit')->permission(['perm' => 'daily_reports.submit', 'membership' => true]);
    Route::post('/stores/{id}/daily-reports/{rid}/validate', [DailyReportController::class, 'validate'])->name('admin.daily_reports.validate')->permission('daily_reports.approve');
    Route::post('/stores/{id}/daily-reports/{rid}/send-mail', [DailyReportController::class, 'sendMail'])->name('admin.daily_reports.send_mail')->permission('daily_reports.approve');
    Route::get('/stores/{id}/daily-reports/{rid}/pdf', [DailyReportController::class, 'previewPdf'])->name('admin.daily_reports.pdf')->permission(['perm' => 'daily_reports.view', 'membership' => true]);
    Route::get('/stores/{id}/daily-reports/{rid}/pdf/download', [DailyReportController::class, 'downloadPdf'])->name('admin.daily_reports.pdf_download')->permission(['perm' => 'daily_reports.view', 'membership' => true]);
    Route::post('/stores/{id}/daily-reports/{rid}/delete', [DailyReportController::class, 'destroy'])->name('admin.daily_reports.delete')->permission('daily_reports.delete');
});

// =============================================================================
// Daily Report — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {
    Route::post('/daily-reports/{id}/submit', [ApiDailyReportController::class, 'submit'])->name('api.v1.daily_reports.submit')->permission(['perm' => 'daily_reports.submit', 'membership' => true]);
    Route::post('/daily-reports/{id}/validate', [ApiDailyReportController::class, 'validate'])->name('api.v1.daily_reports.validate')->permission('daily_reports.approve');
    Route::get('/daily-reports', [ApiDailyReportController::class, 'index'])->name('api.v1.daily_reports.index')->permission(['perm' => 'daily_reports.view', 'membership' => true]);
    Route::post('/daily-reports', [ApiDailyReportController::class, 'store'])->name('api.v1.daily_reports.store')->permission(['perm' => 'daily_reports.create', 'membership' => true]);
    Route::get('/daily-reports/{id}', [ApiDailyReportController::class, 'show'])->name('api.v1.daily_reports.show')->permission(['perm' => 'daily_reports.view', 'membership' => true]);
    Route::put('/daily-reports/{id}', [ApiDailyReportController::class, 'update'])->name('api.v1.daily_reports.update')->permission(['perm' => 'daily_reports.update', 'membership' => true]);
    Route::delete('/daily-reports/{id}', [ApiDailyReportController::class, 'destroy'])->name('api.v1.daily_reports.destroy')->permission('daily_reports.delete');
});
