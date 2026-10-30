<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use kintai\Bundles\Installed\StorePhoto\Controllers\Web\StorePhotoController;


// =============================================================================
// Store Photos — Routes Web (admin)
// =============================================================================

Route::prefix('admin')->middleware([AuthMiddleware::class, PermissionMiddleware::class])->group(function () {
    Route::get('/photos', [StorePhotoController::class, 'index'])->name('admin.photos.index')->permission('photos.view');
    Route::get('/photos/settings', [StorePhotoController::class, 'settings'])->name('admin.photos.settings')->permission('photos.update');
    Route::post('/photos/settings', [StorePhotoController::class, 'saveSettings'])->name('admin.photos.settings.save')->permission('photos.update');
    Route::get('/photos/create', [StorePhotoController::class, 'create'])->name('admin.photos.create')->permission('photos.create');
    Route::post('/photos/create', [StorePhotoController::class, 'store'])->name('admin.photos.store')->permission('photos.create');
    Route::post('/photos/{id}/upload', [StorePhotoController::class, 'uploadFile'])->name('admin.photos.upload_file')->permission('photos.create');
    Route::post('/photos/image/{image_id}/rotate', [StorePhotoController::class, 'rotateImage'])->name('admin.photos.image.rotate')->permission('photos.update');
    Route::get('/photos/{store_id}/{id}', [StorePhotoController::class, 'show'])->name('admin.photos.show')->permission('photos.view');
    Route::post('/photos/{store_id}/{id}/delete', [StorePhotoController::class, 'delete'])->name('admin.photos.delete')->permission('photos.delete');
});
