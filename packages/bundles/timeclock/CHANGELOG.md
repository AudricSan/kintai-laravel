# Changelog

## 1.1.0

Heures supplémentaires paramétrables : réglages de l'instance (activation, seuils journalier et hebdomadaire en heures, premier jour de la semaine ; désactivées par défaut) et page `/timeclock/settings` réservée à l'Owner, `OvertimeCalculator` (calcul pur), notifications de l'employé et des gérants du magasin à chaque seuil atteint (une fois par seuil et par période, table `timeclock_overtime_alerts`), heures supplémentaires visibles dans « Pointage », la page des gérants et l'export. La semaine affichée suit le premier jour de semaine réglé (lundi par défaut, comme avant). Nouvelle migration.

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `timeclock` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), widgets, export (`ExportDataset`), API `/api/v1/timeclock` (anciennes URL `/api/v1/timeclocks/...`).

Écarts : horodatages `clock_in_at` / `clock_out_at` en UTC (colonnes `timestamp`, au lieu de textes locaux) ; entrée rattachée au shift du jour (`shift_id`) avec un indicateur de retard (seuil de 5 min, réglage de l'instance) ; pas de file de pointage hors ligne dans le navigateur (la page web exige d'être en ligne, l'API accepte `client_time`) ; l'alerte « pointages oubliés » et les retards du widget RH, qui lisaient les pointages depuis le Core, n'existent plus (le contrat n'a pas de fournisseur de présence) ; pas de calcul d'heures supplémentaires (ajouté en 1.1.0).
