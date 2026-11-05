# Pointage (`kintai/bundle-timeclock`)

Entrée et sortie des employés, rattachées à leur shift du jour, historique, correction et suppression par les gérants, export.

- Écrans : `/timeclock` (pointer l'entrée et la sortie, pointages du jour et de la semaine avec leur total, libre-service pour les membres d'un magasin) et `/timeclock/manage` (pointages des magasins du gérant : filtres par jour et magasin, correction des heures, suppression après confirmation).
- Permissions `timeclock.view`, `timeclock.update`, `timeclock.delete`, avec la portée magasin du Core. Accordées au rôle `manager` à la première activation ; les employés pointent et lisent les leurs sans permission.
- Fonctionnalité de magasin `timeclock` : sans elle, un magasin n'accepte plus de pointage.
- Un seul pointage en cours par employé. À l'entrée, le shift de l'employé dans le magasin ce jour-là est retrouvé (`shift_id`) ; une entrée plus de `timeclock.late_threshold_minutes` minutes (réglage de l'instance, 5 par défaut) après le début de ce shift est signalée « en retard » (écrans et API).
- Pointage hors ligne : l'API accepte `client_time` (heure réelle du geste), crue dans une fenêtre de 24 h dans le passé et 2 min dans le futur, sinon l'heure du serveur est utilisée ; une sortie rejouée avant l'entrée retombe sur l'heure du serveur.
- Widgets : « Pointage » (employé, avec les boutons d'entrée et de sortie) et « Pointages actifs » (gérant). Export `Time clock` (CSV, Excel, PDF) : les pointages de la période, `timeclock.view` pour ceux des autres, chacun pour les siens.
- Journal : `timeclock.clock_in`, `timeclock.clock_out`, `timeclock.edited`, `timeclock.deleted`.
- API mobile : `/api/v1/timeclock` (voir `docs/API-V1.md`).
- Données : table `timeclocks` (horodatages en UTC). Les IP et notes sont effacées à l'anonymisation du compte (les heures restent) ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
