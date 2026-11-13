<?php

use App\License\LicenseState;
use App\Settings\AppSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chaque tâche porte un nom : c'est lui que l'écran « Tâches planifiées » affiche et sous lequel le résultat est mémorisé.
// Elles tournent par `php artisan schedule:run` (cron du serveur) ou par l'appel HTTP `/cron/run` (hébergement sans terminal).
Schedule::command('kintai:notifications:purge')->daily()->name('kintai:notifications:purge');
Schedule::command('kintai:activity:purge')->daily()->name('kintai:activity:purge');
// Sauvegarde automatique : seulement si l'Owner ne l'a pas désactivée (la commande revérifie le réglage).
Schedule::command('kintai:backup:run')->dailyAt('03:00')->name('kintai:backup:run')->when(fn (): bool => app(AppSettings::class)->backupAutoEnabled());
// Rafraîchit sans le visiteur le cache des mises à jour (lu sans réseau par l'alerte système), juste avant la passe horaire.
Schedule::command('kintai:update:refresh')->hourlyAt(50)->name('kintai:update:refresh');
// Licence : validation quotidienne auprès du LicenseManager, seulement si une clé est réglée.
Schedule::command('kintai:license:validate')->dailyAt('05:30')->name('kintai:license:validate')->when(fn (): bool => app(LicenseState::class)->hasKey());
// Alertes système de l'Owner (migrations en attente, sauvegardes, canaux de notification).
Schedule::command('kintai:system:alerts')->hourly()->name('kintai:system:alerts');
// Documentation intégrée : synchronisation quotidienne du wiki GitHub.
Schedule::command('kintai:docs:sync')->dailyAt('04:00')->name('kintai:docs:sync');
