<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\HiringReport\Http\PdfController;

// Préfixe `hiring-report`, noms `hiring-report.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Le contenu est personnel : chaque route exige sa permission (`can:`, sur au moins un magasin) et chaque composant ou
// contrôleur la revérifie sur le magasin du rapport. Aucune route sans droit.
Route::livewire('/', 'hiring-report.index')->middleware('can:hiring_reports.view')->name('index');
Route::livewire('create', 'hiring-report.form')->middleware('can:hiring_reports.create')->name('create');
Route::livewire('{report}', 'hiring-report.show')->whereNumber('report')->middleware('can:hiring_reports.view')->name('show');
Route::livewire('{report}/edit', 'hiring-report.form')->whereNumber('report')->middleware('can:hiring_reports.update')->name('edit');
Route::middleware('can:hiring_reports.view')->group(function (): void {
    Route::get('{report}/pdf', [PdfController::class, 'preview'])->whereNumber('report')->name('pdf');
    Route::get('{report}/pdf/download', [PdfController::class, 'download'])->whereNumber('report')->name('pdf.download');
});
