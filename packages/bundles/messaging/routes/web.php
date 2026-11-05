<?php

use Illuminate\Support\Facades\Route;

// Préfixe `messaging`, noms `messaging.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Libre-service : l'accès à chaque conversation se vérifie dans le composant (participant, sinon 404).
Route::livewire('/', 'messaging.index')->name('index');
Route::livewire('compose', 'messaging.compose')->name('compose');
Route::livewire('threads/{id}', 'messaging.thread')->whereNumber('id')->name('thread');
