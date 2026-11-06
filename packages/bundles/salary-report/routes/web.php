<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\SalaryReport\Http\ExportController;
use Kintai\Bundles\SalaryReport\Http\PdfController;

// Préfixe `salary-report`, noms `salary-report.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Les salaires sont très sensibles : chaque route exige sa permission (`can:`, sur au moins un magasin) et chaque
// composant ou contrôleur la revérifie sur le magasin du rapport, avec la fonctionnalité de magasin. Aucune route sans droit.
Route::livewire('/', 'salary-report.index')->middleware('can:salary_reports.view')->name('index');
Route::livewire('create', 'salary-report.form')->middleware('can:salary_reports.generate')->name('create');
Route::livewire('settings', 'salary-report.settings')->middleware('can:salary_reports.settings')->name('settings');

Route::middleware('can:salary_reports.export')->prefix('export')->name('export.')->group(function (): void {
    Route::get('json', [ExportController::class, 'json'])->name('json');
    Route::get('pdf', [ExportController::class, 'preview'])->name('pdf');
    Route::get('pdf/download', [ExportController::class, 'download'])->name('pdf.download');
});

Route::livewire('{report}', 'salary-report.show')->whereNumber('report')->middleware('can:salary_reports.view')->name('show');
Route::livewire('{report}/edit', 'salary-report.form')->whereNumber('report')->middleware('can:salary_reports.generate')->name('edit');
Route::middleware('can:salary_reports.export')->group(function (): void {
    Route::get('{report}/pdf', [PdfController::class, 'preview'])->whereNumber('report')->name('pdf');
    Route::get('{report}/pdf/download', [PdfController::class, 'download'])->whereNumber('report')->name('pdf.download');
});
