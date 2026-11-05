# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `timeclock` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), widgets, export (`ExportDataset`), API `/api/v1/timeclock` (anciennes URL `/api/v1/timeclocks/...`).

Écarts : horodatages `clock_in_at` / `clock_out_at` en UTC (colonnes `timestamp`, au lieu de textes locaux) ; entrée rattachée au shift du jour (`shift_id`) avec un indicateur de retard (seuil de 5 min, réglage de l'instance) ; pas de file de pointage hors ligne dans le navigateur (la page web exige d'être en ligne, l'API accepte `client_time`) ; l'alerte « pointages oubliés » et les retards du widget RH, qui lisaient les pointages depuis le Core, n'existent plus (le contrat n'a pas de fournisseur de présence) ; pas de calcul d'heures supplémentaires (l'ancien bundle n'en portait pas).
