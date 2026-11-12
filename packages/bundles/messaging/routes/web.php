<?php

use Illuminate\Support\Facades\Route;

// Préfixe `messaging`, noms `messaging.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Permissions `messaging.view` (liste, conversation) et `messaging.create` (nouveau message). L'accès à chaque conversation
// se vérifie en plus dans le composant (participant, sinon 404 ; permission du magasin de la conversation, sinon 403).
Route::livewire('/', 'messaging.index')->middleware('can:messaging.view')->name('index');
Route::livewire('compose', 'messaging.compose')->middleware('can:messaging.create')->name('compose');
Route::livewire('threads/{id}', 'messaging.thread')->whereNumber('id')->middleware('can:messaging.view')->name('thread');
