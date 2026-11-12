<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\StorePhotos\Http\ImageController;

// Préfixe `store-photos`, noms `store-photos.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Les droits par magasin se vérifient dans les composants (portée magasin) ; le fichier d'une photo ne se lit que par `image`.
Route::livewire('/', 'store-photos.index')->middleware('can:photos.view')->name('index');
Route::livewire('create', 'store-photos.create')->middleware('can:photos.create')->name('create');
Route::livewire('settings', 'store-photos.settings')->name('settings');
Route::livewire('{id}', 'store-photos.show')->whereNumber('id')->middleware('can:photos.view')->name('show');
Route::get('images/{image}', ImageController::class)->whereNumber('image')->name('image');
