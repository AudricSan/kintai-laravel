<?php

use Illuminate\Support\Facades\Route;

// Préfixe `shift-claim`, noms `shift-claim.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// `index` : la bourse des magasins de l'employé (libre-service) ; `manage` : shifts disponibles et candidatures du gérant.
Route::livewire('/', 'shift-claim.index')->name('index');
Route::livewire('manage', 'shift-claim.manage')->middleware('can:open_shifts.view')->name('manage');
