# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `timeoff` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), `TimeOffChecker` et `TimeOffProvider` du contrat, demandes en attente, widgets, notifications de décision (`Notifier`), API `/api/v1/timeoff/requests` (ancienne URL `/api/v1/timeoff-requests`).

Écarts : les dates sont de vraies colonnes `date` ; l'annulation d'une demande est une action de l'employé (la décision d'un gérant n'est plus possible par un simple `PUT status`, mais par `approve` / `refuse`) ; la carte « Congés en attente » du tableau de bord employé (statistique du Core d'avant) devient le widget « Mes congés en attente ».

## 1.1.0

Exige le contrat 1.7 (`requiredContract` `^1.7`) : `LeaveStatistics` fournit les demandes de congé de tous statuts (`Kintai\Contracts\Planning\TimeOffStatistics`) aux statistiques d'un autre bundle (analytics) ; liée au Core tant que le bundle est activé, comme `ApprovedTimeOff`.
