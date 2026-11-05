# Congés (`kintai/bundle-timeoff`)

Demandes de congé des employés, validation par les gérants, saisie d'un congé par un gérant. Les congés approuvés sont pris en compte par le Core.

- Écrans : `/timeoff` (mes congés : demande, suivi, annulation, libre-service pour les membres d'un magasin) et `/timeoff/manage` (demandes des magasins du gérant : filtres, approbation, refus avec note, suppression, saisie d'un congé pour un employé).
- Types : vacances, maladie, personnel, sans solde, autre. Statuts : en attente, approuvé, refusé, annulé. Seule une demande en attente se décide ou s'annule.
- Permissions `timeoff.view`, `timeoff.create`, `timeoff.update`, `timeoff.approve`, `timeoff.delete`, avec la portée magasin du Core. Accordées au rôle `manager` à la première activation ; les employés n'en ont pas besoin pour leurs propres demandes.
- Fonctionnalité de magasin `timeoff` : sans elle, un magasin n'accepte plus de demande.
- Intégration au Core par le contrat : `TimeOffChecker` (un shift sur un congé approuvé est bloqué par le garde-fou du planning) et `TimeOffProvider` (congés approuvés en journée entière dans le flux iCal) remplacent les liaisons vides du Core tant que le bundle est activé ; `PendingRequestProvider` (page « Demandes » et compteur) ; widgets « Congés en attente » (gérant) et « Mes congés en attente » (employé).
- Notifications : `timeoff_submitted` (aux approbateurs du magasin), `timeoff_approved`, `timeoff_refused` (à l'employé, aussi par e-mail), lues automatiquement quand la demande est affichée.
- Journal : `timeoff.created`, `.created_by_manager`, `.approved`, `.refused`, `.cancelled`, `.updated`, `.deleted`.
- API mobile : `/api/v1/timeoff/requests` (voir `docs/API-V1.md`).
- Données : table `timeoff_requests`. Les motifs et notes sont effacés à l'anonymisation du compte ; désactiver le bundle conserve tout (et le Core ne voit plus de congé).
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
