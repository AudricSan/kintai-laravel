<?php

use Illuminate\Support\Facades\Route;

// Préfixe `timeclock`, noms `timeclock.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// `index` : « Pointage » (libre-service, pour les membres d'un magasin) ; `manage` : les pointages des magasins du gérant.
Route::livewire('/', 'timeclock.my-clock')->name('index');
Route::livewire('manage', 'timeclock.manage')->middleware('can:timeclock.view')->name('manage');
// Réglages des heures supplémentaires : Owner seulement (vérifié dans le composant).
Route::livewire('settings', 'timeclock.settings')->name('settings');
