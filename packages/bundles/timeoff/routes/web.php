<?php

use Illuminate\Support\Facades\Route;

// Préfixe `timeoff`, noms `timeoff.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// `index` : « Mes congés » (libre-service, pour les membres d'un magasin) ; `manage` : les demandes des magasins du gérant.
Route::livewire('/', 'timeoff.my-requests')->name('index');
Route::livewire('manage', 'timeoff.manage')->middleware('can:timeoff.view')->name('manage');
