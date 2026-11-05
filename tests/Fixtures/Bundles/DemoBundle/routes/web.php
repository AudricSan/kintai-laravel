<?php

use Illuminate\Support\Facades\Route;

// Préfixe `demo`, noms `demo.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
Route::get('/', fn () => view('demo::index'))->middleware('can:demo.view')->name('index');
