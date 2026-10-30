<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Contract\Http\Middleware\AuthMiddleware;
use kintai\Contract\Http\Middleware\ApiAuthMiddleware;
use kintai\Contract\Http\Middleware\ApiPermissionMiddleware;
use kintai\Contract\Http\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\TimeOff\Controllers\Web\EmployeeTimeoffController;
use kintai\Bundles\Installed\TimeOff\Controllers\Web\AdminTimeoffController;
use kintai\Bundles\Installed\TimeOff\Controllers\Api\TimeoffRequestController as ApiTimeoffRequestController;


// =============================================================================
// TimeOff — Routes Web (employé)
// =============================================================================

Route::prefix('employee')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('/timeoff', [EmployeeTimeoffController::class, 'timeoff'])->name('employee.timeoff');
    Route::post('/timeoff', [EmployeeTimeoffController::class, 'storeTimeoff'])->name('employee.timeoff.store');
    Route::post('/timeoff/{id}/cancel', [EmployeeTimeoffController::class, 'cancelTimeoff'])->name('employee.timeoff.cancel');
});

// =============================================================================
// TimeOff — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/timeoff', [AdminTimeoffController::class, 'timeoff'])->name('admin.timeoff')->permission('timeoff.view');
    Route::get('/timeoff/create', [AdminTimeoffController::class, 'createTimeoff'])->name('admin.timeoff.create')->permission('timeoff.create');
    Route::post('/timeoff/create', [AdminTimeoffController::class, 'storeTimeoffForEmployee'])->name('admin.timeoff.store')->permission('timeoff.create');
    Route::post('/timeoff/{id}/approve', [AdminTimeoffController::class, 'approveTimeoff'])->name('admin.timeoff.approve')->permission('timeoff.approve');
    Route::post('/timeoff/{id}/refuse', [AdminTimeoffController::class, 'refuseTimeoff'])->name('admin.timeoff.refuse')->permission('timeoff.approve');
    Route::post('/timeoff/{id}/delete', [AdminTimeoffController::class, 'deleteTimeoff'])->name('admin.timeoff.delete')->permission('timeoff.delete');
});

// =============================================================================
// TimeOff — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {
    Route::get('/timeoff-requests', [ApiTimeoffRequestController::class, 'index'])->name('api.v1.timeoff.index')->permission(['perm' => 'timeoff.view', 'self' => 'user_id']);
    Route::post('/timeoff-requests', [ApiTimeoffRequestController::class, 'store'])->name('api.v1.timeoff.store')->permission(['perm' => 'timeoff.create', 'self' => 'user_id']);
    Route::get('/timeoff-requests/{id}', [ApiTimeoffRequestController::class, 'show'])->name('api.v1.timeoff.show')->permission('timeoff.view');
    Route::put('/timeoff-requests/{id}', [ApiTimeoffRequestController::class, 'update'])->name('api.v1.timeoff.update')->permission('timeoff.update');
    Route::delete('/timeoff-requests/{id}', [ApiTimeoffRequestController::class, 'destroy'])->name('api.v1.timeoff.destroy')->permission('timeoff.delete');
});
