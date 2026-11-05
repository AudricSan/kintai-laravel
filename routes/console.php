<?php

use App\Settings\AppSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('kintai:notifications:purge')->daily();
Schedule::command('kintai:activity:purge')->daily();
// Sauvegarde automatique : seulement si l'Owner ne l'a pas désactivée (la commande revérifie le réglage).
Schedule::command('kintai:backup:run')->dailyAt('03:00')->when(fn (): bool => app(AppSettings::class)->backupAutoEnabled());
