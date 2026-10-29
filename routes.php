<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\Timeclock\Controllers\Web\EmployeeTimeclockController;
use kintai\Bundles\Installed\Timeclock\Controllers\Web\AdminTimeclockController;
use kintai\Bundles\Installed\Timeclock\Controllers\Api\TimeclockController as ApiTimeclockController;


// =============================================================================
// Timeclock — Routes Web Employé
// =============================================================================

Route::prefix('employee')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('/timeclock', [EmployeeTimeclockController::class, 'timeclock'])->name('employee.timeclock');
    Route::post('/timeclock/clock-in', [EmployeeTimeclockController::class, 'clockIn'])->name('employee.timeclock.clock_in');
    Route::post('/timeclock/clock-out', [EmployeeTimeclockController::class, 'clockOut'])->name('employee.timeclock.clock_out');
});

// =============================================================================
// Timeclock — Routes Web Admin
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/timeclocks', [AdminTimeclockController::class, 'timeclocks'])->name('admin.timeclocks')->permission('timeclock.view');
    Route::post('/timeclocks/{id}/edit', [AdminTimeclockController::class, 'timeclocksEdit'])->name('admin.timeclocks.edit')->permission('timeclock.update');
    Route::post('/timeclocks/{id}/delete', [AdminTimeclockController::class, 'timeclocksDelete'])->name('admin.timeclocks.delete')->permission('timeclock.delete');
});

// =============================================================================
// Timeclock — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {
    Route::post('/timeclocks/clock-in', [ApiTimeclockController::class, 'clockIn'])->name('api.v1.timeclock.clock_in')->permission(['perm' => 'timeclock.update', 'self' => 'user_id']);
    Route::post('/timeclocks/clock-out', [ApiTimeclockController::class, 'clockOut'])->name('api.v1.timeclock.clock_out')->permission(['perm' => 'timeclock.update', 'self' => 'user_id']);
    Route::get('/timeclocks', [ApiTimeclockController::class, 'index'])->name('api.v1.timeclock.index')->permission(['perm' => 'timeclock.view', 'self' => 'user_id']);
    Route::get('/timeclocks/{id}', [ApiTimeclockController::class, 'show'])->name('api.v1.timeclock.show')->permission('timeclock.view');
    Route::put('/timeclocks/{id}', [ApiTimeclockController::class, 'update'])->name('api.v1.timeclock.update')->permission('timeclock.update');
    Route::delete('/timeclocks/{id}', [ApiTimeclockController::class, 'destroy'])->name('api.v1.timeclock.destroy')->permission('timeclock.delete');
});
