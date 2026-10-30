<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Contract\Http\Middleware\AuthMiddleware;
use kintai\Contract\Http\Middleware\ApiAuthMiddleware;
use kintai\Contract\Http\Middleware\ApiPermissionMiddleware;
use kintai\Contract\Http\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\ShiftClaim\Controllers\Web\EmployeeShiftClaimController;
use kintai\Bundles\Installed\ShiftClaim\Controllers\Web\AdminShiftClaimController;
use kintai\Bundles\Installed\ShiftClaim\Controllers\Api\ShiftClaimController as ApiShiftClaimController;


// =============================================================================
// ShiftClaim — Routes Web (employé)
// =============================================================================

Route::prefix('employee')->middleware([AuthMiddleware::class])->group(function () {
    Route::get('/open-shifts', [EmployeeShiftClaimController::class, 'openShifts'])->name('employee.open_shifts');
    Route::post('/open-shifts/{id}/claim', [EmployeeShiftClaimController::class, 'claimShift'])->name('employee.open_shifts.claim');
    Route::post('/open-shifts/{id}/withdraw', [EmployeeShiftClaimController::class, 'withdrawShiftClaim'])->name('employee.open_shifts.withdraw');
});

// =============================================================================
// ShiftClaim — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/open-shifts', [AdminShiftClaimController::class, 'openShifts'])->name('admin.open_shifts')->permission('open_shifts.view');
    Route::get('/open-shifts/publish', [AdminShiftClaimController::class, 'selectShiftToPublish'])->name('admin.open_shifts.select')->permission('open_shifts.publish');
    Route::post('/shifts/{id}/publish', [AdminShiftClaimController::class, 'publishShift'])->name('admin.shifts.publish')->permission('open_shifts.publish');
    Route::post('/shifts/{id}/unpublish', [AdminShiftClaimController::class, 'unpublishShift'])->name('admin.shifts.unpublish')->permission('open_shifts.publish');
    Route::post('/open-shifts/{id}/approve', [AdminShiftClaimController::class, 'approveShiftClaim'])->name('admin.open_shifts.approve')->permission('open_shifts.approve');
    Route::post('/open-shifts/{id}/reject', [AdminShiftClaimController::class, 'rejectShiftClaim'])->name('admin.open_shifts.reject')->permission('open_shifts.approve');
});

// =============================================================================
// ShiftClaim — Routes API
// =============================================================================

Route::prefix('api/v1')->middleware([ApiAuthMiddleware::class, ApiPermissionMiddleware::class])->group(function () {
    Route::get('/shift-claims', [ApiShiftClaimController::class, 'index'])->name('api.v1.shift_claims.index')->permission(['perm' => 'open_shifts.view', 'self' => 'user_id']);
    Route::post('/shift-claims', [ApiShiftClaimController::class, 'store'])->name('api.v1.shift_claims.store')->permission(['perm' => 'open_shifts.approve', 'self' => 'user_id']);
    Route::get('/shift-claims/{id}', [ApiShiftClaimController::class, 'show'])->name('api.v1.shift_claims.show')->permission('open_shifts.view');
    Route::put('/shift-claims/{id}', [ApiShiftClaimController::class, 'update'])->name('api.v1.shift_claims.update')->permission('open_shifts.approve');
    Route::delete('/shift-claims/{id}', [ApiShiftClaimController::class, 'destroy'])->name('api.v1.shift_claims.destroy')->permission('open_shifts.approve');
});
