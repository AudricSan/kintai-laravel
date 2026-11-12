<?php

use Illuminate\Support\Facades\Route;

// Préfixe `analytics`, noms `analytics.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Le droit `payroll.view` et la portée magasin se vérifient dans les composants, à chaque rendu.
Route::livewire('/', 'analytics.stats')->name('stats');
Route::livewire('employees', 'analytics.employees')->name('employees');
Route::livewire('employee', 'analytics.employee')->name('employee');
Route::livewire('profitability', 'analytics.profitability')->name('profitability');
