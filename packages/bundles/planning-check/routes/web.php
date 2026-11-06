<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\PlanningCheck\Http\PdfController;

// Préfixe `planning-check`, noms `planning-check.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Les droits par magasin se vérifient dans les composants (portée magasin, fonctionnalité de magasin).
Route::livewire('/', 'planning-check.index')->name('index');
Route::livewire('settings', 'planning-check.settings')->name('settings');
Route::get('{check}/pdf', PdfController::class)->whereNumber('check')->name('pdf');
