<?php

use Illuminate\Support\Facades\Route;

// Préfixe `planning-import`, noms `planning-import.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Le droit `shifts.import` et la portée magasin se vérifient dans les composants et à chaque action.
Route::livewire('/', 'planning-import.index')->name('index');
Route::livewire('settings', 'planning-import.settings')->name('settings');
