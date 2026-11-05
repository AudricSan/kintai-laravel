<?php

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Bundles\DemoBundle\DemoApiController;

// Préfixe `api/v1/demo`, noms `api.v1.demo.*`, pile de l'API du Core : posés par BundleServiceProvider.
Route::get('/items', [DemoApiController::class, 'index'])->name('items.index');
Route::post('/items', [DemoApiController::class, 'store'])->name('items.store');
Route::get('/conflict', [DemoApiController::class, 'conflict'])->name('conflict');
