<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Core\Middleware\AuthMiddleware;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\ShiftSwap\Controllers\Web\EmployeeSwapController;
use kintai\Bundles\Installed\ShiftSwap\Controllers\Web\AdminSwapController;
use kintai\Bundles\Installed\ShiftSwap\Controllers\Api\ShiftSwapRequestController as ApiShiftSwapRequestController;


// =============================================================================
// ShiftSwap — Routes Web (employé)
// =============================================================================

Route::prefix('employee')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('/swaps', [EmployeeSwapController::class, 'swaps'])->name('employee.swaps');
    Route::get('/swaps/create', [EmployeeSwapController::class, 'createSwap'])->name('employee.swaps.create');
    Route::post('/swaps/create', [EmployeeSwapController::class, 'storeSwap'])->name('employee.swaps.store');
    Route::post('/swaps/{id}/accept', [EmployeeSwapController::class, 'acceptSwap'])->name('employee.swaps.accept');
    Route::post('/swaps/{id}/refuse', [EmployeeSwapController::class, 'refuseSwap'])->name('employee.swaps.refuse');
    Route::post('/swaps/{id}/cancel', [EmployeeSwapController::class, 'cancelSwap'])->name('employee.swaps.cancel');
});

// =============================================================================
// ShiftSwap — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/swap-requests', [AdminSwapController::class, 'swapRequests'])->name('admin.swap_requests')->permission('swaps.view');
    Route::get('/swap-requests/create', [AdminSwapController::class, 'createSwap'])->name('admin.swap_requests.create')->permission('swaps.create');
    Route::post('/swap-requests/create', [AdminSwapController::class, 'storeSwap'])->name('admin.swap_requests.store')->permission('swaps.create');
    Route::post('/swap-requests/{id}/approve', [AdminSwapController::class, 'approveSwap'])->name('admin.swap.approve')->permission('swaps.approve');
    Route::post('/swap-requests/{id}/refuse', [AdminSwapController::class, 'refuseSwap'])->name('admin.swap.refuse')->permission('swaps.approve');
    Route::post('/swap-requests/{id}/delete', [AdminSwapController::class, 'deleteSwap'])->name('admin.swap.delete')->permission('swaps.delete');
});

// =============================================================================
// ShiftSwap — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {
    Route::get('/shift-swap-requests', [ApiShiftSwapRequestController::class, 'index'])->name('api.v1.swap.index')->permission(['perm' => 'swaps.view', 'self' => 'user_id']);
    Route::post('/shift-swap-requests', [ApiShiftSwapRequestController::class, 'store'])->name('api.v1.swap.store')->permission('swaps.create');
    Route::get('/shift-swap-requests/{id}', [ApiShiftSwapRequestController::class, 'show'])->name('api.v1.swap.show')->permission('swaps.view');
    Route::put('/shift-swap-requests/{id}', [ApiShiftSwapRequestController::class, 'update'])->name('api.v1.swap.update')->permission('swaps.update');
    Route::delete('/shift-swap-requests/{id}', [ApiShiftSwapRequestController::class, 'destroy'])->name('api.v1.swap.destroy')->permission('swaps.delete');
});
