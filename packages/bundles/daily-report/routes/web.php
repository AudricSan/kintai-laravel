<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\DailyReport\Http\PdfController;

// Préfixe `daily-report`, noms `daily-report.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Les droits par magasin se vérifient dans les composants (portée magasin, fonctionnalité de magasin) ; chacun voit ses
// propres rapports sans permission.
Route::livewire('/', 'daily-report.index')->name('index');
Route::livewire('create', 'daily-report.form')->name('create');
Route::livewire('settings', 'daily-report.settings')->name('settings');
Route::livewire('{report}', 'daily-report.show')->whereNumber('report')->name('show');
Route::livewire('{report}/edit', 'daily-report.form')->whereNumber('report')->name('edit');
Route::get('{report}/pdf', [PdfController::class, 'preview'])->whereNumber('report')->name('pdf');
Route::get('{report}/pdf/download', [PdfController::class, 'download'])->whereNumber('report')->name('pdf.download');
