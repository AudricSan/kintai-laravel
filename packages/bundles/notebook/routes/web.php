<?php

use Illuminate\Support\Facades\Route;

// Préfixe `notebook`, noms `notebook.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// La portée magasin de chaque action est décidée par la page (NotebookAccess), pas par la route.
Route::livewire('/', 'notebook.index')->middleware('can:notebook.view')->name('index');
