<?php

use Illuminate\Support\Facades\Route;

// Préfixe `team-directory`, noms `team-directory.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Libre-service : la visibilité est décidée par les magasins partagés et le choix de chacun, pas par un droit.
Route::livewire('/', 'team-directory.index')->name('index');
Route::livewire('profile', 'team-directory.edit-profile')->name('profile');
Route::livewire('{user}', 'team-directory.show')->whereNumber('user')->name('show');
