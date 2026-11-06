<?php

use Illuminate\Support\Facades\Route;
use Kintai\Bundles\GoogleCalendar\Http\CallbackController;

// Préfixe `google-calendar`, noms `google-calendar.*`, middleware `web` et `auth` : posés par BundleServiceProvider.
// Libre-service : chacun ne gère que son propre agenda. Les réglages de l'instance sont réservés à l'Owner.
Route::livewire('/', 'google-calendar.profile')->name('profile');
Route::get('callback', CallbackController::class)->middleware('throttle:20,1')->name('callback');
Route::livewire('settings', 'google-calendar.settings')->middleware('can:manage-settings')->name('settings');
