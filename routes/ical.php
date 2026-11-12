<?php

use App\Http\Controllers\IcalFeedController;
use Illuminate\Support\Facades\Route;

// Hors du groupe `web` (voir bootstrap/app.php) : ni session, ni cookie, ni CSRF. Seule la limitation de débit s'applique.
Route::get('ical/{token}/{store}/shifts.ics', IcalFeedController::class)
    ->middleware('throttle:ical')
    ->name('ical.feed');
