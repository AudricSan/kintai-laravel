<?php

use Illuminate\Support\Facades\Route;

// Préfixe `shift-swap`, noms `shift-swap.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// `index` et `create` : libre-service des membres d'un magasin ; `manage` : les échanges des magasins du gérant.
Route::livewire('/', 'shift-swap.index')->name('index');
Route::livewire('create', 'shift-swap.create')->name('create');
Route::livewire('manage', 'shift-swap.manage')->middleware('can:swaps.view')->name('manage');
