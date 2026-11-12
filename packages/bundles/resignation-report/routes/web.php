<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\ResignationReport\Http\PdfController;

// Préfixe `resignation-report`, noms `resignation-report.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Le contenu est personnel : chaque route exige sa permission (`can:`, sur au moins un magasin) et chaque composant ou
// contrôleur la revérifie sur le magasin du rapport. Aucune route sans droit.
Route::livewire('/', 'resignation-report.index')->middleware('can:resignation_reports.view')->name('index');
Route::livewire('create', 'resignation-report.form')->middleware('can:resignation_reports.create')->name('create');
Route::livewire('{report}', 'resignation-report.show')->whereNumber('report')->middleware('can:resignation_reports.view')->name('show');
Route::livewire('{report}/edit', 'resignation-report.form')->whereNumber('report')->middleware('can:resignation_reports.update')->name('edit');
Route::middleware('can:resignation_reports.view')->group(function (): void {
    Route::get('{report}/pdf', [PdfController::class, 'preview'])->whereNumber('report')->name('pdf');
    Route::get('{report}/pdf/download', [PdfController::class, 'download'])->whereNumber('report')->name('pdf.download');
});
