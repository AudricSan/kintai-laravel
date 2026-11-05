# Échanges de shifts (`kintai/bundle-shift-swap`)

Un employé propose l'un de ses shifts à venir contre un shift à venir d'un collègue du même magasin ; le collègue accepte ou refuse ; un gérant valide ; le Core applique l'échange. Un gérant peut aussi appliquer d'emblée un échange entre deux employés.

- Écrans : `/shift-swap` (mes échanges : demandes reçues à accepter ou refuser, demandes envoyées à retirer, libre-service pour les membres d'un magasin), `/shift-swap/create` (nouvelle demande : collègue, mon shift, son shift, motif) et `/shift-swap/manage` (échanges des magasins du gérant : filtres, approbation, refus, suppression, échange appliqué d'emblée).
- Parcours : en attente de la réponse du collègue → en attente d'un gérant (le collègue a accepté) → appliqué, ou refusé / annulé. Le demandeur retire sa demande tant qu'elle n'est pas décidée. Un gérant ne peut approuver qu'une demande que le collègue a acceptée ; il peut la refuser à tout moment.
- Application par le Core : l'approbation appelle `ShiftExchanger` du contrat 1.3. Les deux shifts sont remplacés par deux nouveaux shifts de même créneau (les anciens partent à la corbeille avec `replaced_by_id`). Les garde-fous du planning s'appliquent aux deux affectations : congé approuvé bloquant ; chevauchement, indisponibilité et durée en avertissements à confirmer (fenêtre de confirmation, ou `409 shift_warnings` puis `force` côté API). Le gérant qui approuve a besoin de `swaps.approve` et des droits `shifts.update` et `shifts.create` du magasin.
- Cas concurrents : la demande et les deux shifts sont relus sous verrou dans une transaction. Un shift supprimé, remplacé, réaffecté ou modifié depuis la demande (version `ical_sequence` mémorisée) refuse l'acceptation et l'approbation ; une décision déjà prise ne se rejoue pas ; un shift ne peut appartenir qu'à une seule demande en attente.
- Permissions `swaps.view`, `swaps.create` (appliquer un échange), `swaps.update` (motif, API), `swaps.approve`, `swaps.delete`, avec la portée magasin du Core. Accordées au rôle `manager` à la première activation ; les employés n'en ont pas besoin pour leurs propres échanges. Un employé ne peut proposer que ses propres shifts, dans un magasin dont il est membre actif.
- Fonctionnalité de magasin `swaps` : sans elle, un magasin n'accepte plus de demande.
- Suppression : un échange appliqué supprimé rétablit les shifts (nouvel échange par le Core) tant que les deux shifts issus de l'échange sont encore tels que l'échange les a laissés.
- Intégration au Core par le contrat : `PendingRequestProvider` (page « Demandes » et compteur : réponses attendues, demandes suivies, décisions des gérants), widgets « Échanges à approuver » (gérant) et « Échanges en attente de ma réponse » (employé).
- Notifications : `swap_requested`, `swap_peer_accepted`, `swap_peer_refused`, `swap_cancelled`, `swap_to_approve` (aux approbateurs), `swap_accepted` (e-mail), `swap_refused`, `swap_applied` (e-mail), `swap_cancelled_restored` (e-mail) ; l'auteur n'est jamais notifié ; lues automatiquement quand la demande est affichée.
- Journal : `swap.created`, `.created_by_manager`, `.peer_accepted`, `.peer_refused`, `.cancelled`, `.approved`, `.refused`, `.updated`, `.deleted`.
- API mobile : `/api/v1/shift-swap/requests` (voir `docs/API-V1.md`).
- Données : table `shift_swap_requests`. Le motif est effacé à l'anonymisation du compte ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Exige `kintai/contracts` ^1.3.

Tests : `php artisan test --testsuite=Bundles`.
