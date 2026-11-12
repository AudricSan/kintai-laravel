<?php

use App\Http\Controllers\CronRunController;
use Illuminate\Support\Facades\Route;

// Hors du groupe `web` (voir bootstrap/app.php) : ni session, ni cookie, ni CSRF, pas non plus de mode maintenance (les tâches,
// sauvegardes comprises, continuent). Le jeton fait foi ; seule la limitation de débit s'applique.
Route::match(['get', 'post'], 'cron/run', CronRunController::class)
    ->middleware('throttle:cron')
    ->name('cron.run');
