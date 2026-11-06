# Pointage (`kintai/bundle-timeclock`)

Entrée et sortie des employés, rattachées à leur shift du jour, historique, correction et suppression par les gérants, export.

- Écrans : `/timeclock` (pointer l'entrée et la sortie, pointages du jour et de la semaine avec leur total, libre-service pour les membres d'un magasin) et `/timeclock/manage` (pointages des magasins du gérant : filtres par jour et magasin, correction des heures, suppression après confirmation).
- Permissions `timeclock.view`, `timeclock.update`, `timeclock.delete`, avec la portée magasin du Core. Accordées au rôle `manager` à la première activation ; les employés pointent et lisent les leurs sans permission.
- Fonctionnalité de magasin `timeclock` : sans elle, un magasin n'accepte plus de pointage.
- Un seul pointage en cours par employé. À l'entrée, le shift de l'employé dans le magasin ce jour-là est retrouvé (`shift_id`) ; une entrée plus de `timeclock.late_threshold_minutes` minutes (réglage de l'instance, 5 par défaut) après le début de ce shift est signalée « en retard » (écrans et API).
- Pointage hors ligne : l'API accepte `client_time` (heure réelle du geste), crue dans une fenêtre de 24 h dans le passé et 2 min dans le futur, sinon l'heure du serveur est utilisée ; une sortie rejouée avant l'entrée retombe sur l'heure du serveur.
- Widgets : « Pointage » (employé, avec les boutons d'entrée et de sortie) et « Pointages actifs » (gérant). Export `Time clock` (CSV, Excel, PDF) : les pointages de la période, `timeclock.view` pour ceux des autres, chacun pour les siens.
- Journal : `timeclock.clock_in`, `timeclock.clock_out`, `timeclock.edited`, `timeclock.deleted`.
- Heures supplémentaires (désactivées par défaut, rien n'est codé en dur : la réglementation varie selon les pays). Réglages de l'instance, modifiables par l'Owner dans `/timeclock/settings` (lien sur la page des pointages) : `timeclock.overtime_enabled`, `timeclock.overtime_daily_hours` (8 par défaut, 0 à 24, 0 = pas de seuil), `timeclock.overtime_weekly_hours` (40 par défaut, 0 à 168, 0 = pas de seuil) et `timeclock.overtime_week_start` (premier jour de la semaine, 1 = lundi par défaut, 0 = dimanche). Les 8 h / 40 h ne sont que des valeurs de départ.
- Calcul (`OvertimeCalculator`, pur) : semaine calendaire, pointages découpés à minuit local, tous magasins confondus. Ce qui dépasse le seuil journalier est supplémentaire ; ce qui dépasse le seuil hebdomadaire, hors heures déjà comptées par jour, l'est aussi (jamais deux fois). Un pointage en cours compte jusqu'à maintenant.
- Notifications (types `timeclock_overtime` pour l'employé, `timeclock_overtime_manager` pour les détenteurs de `timeclock.view` du magasin du pointage) quand un seuil est atteint, à la sortie ou à la correction d'un pointage ; une seule fois par seuil et par période (jour ou semaine), mémorisé dans `timeclock_overtime_alerts`. Pas de détection tant que l'employé est encore en poste.
- Affichage : « Pointage » montre à l'employé ses propres heures supplémentaires de la semaine et par pointage ; la page des gérants les montre pour les pointages de leurs magasins ; l'export a une colonne « Overtime (min) » (vide si le suivi est désactivé). Un employé ne voit jamais celles des autres.
- API mobile : `/api/v1/timeclock` (voir `docs/API-V1.md`).
- Données : tables `timeclocks` (horodatages en UTC) et `timeclock_overtime_alerts`. Les IP et notes sont effacées à l'anonymisation du compte (les heures restent) ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
