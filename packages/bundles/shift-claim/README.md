# Reprise de shifts ouverts (`kintai/bundle-shift-claim`)

La bourse aux shifts : les shifts ouverts (sans employé) et ceux qu'un gérant propose sont offerts aux employés du magasin, qui candidatent ; un gérant retient une candidature et le Core attribue le shift.

- Écrans : `/shift-claim` (la bourse des magasins de l'employé : candidater avec une note, retirer sa candidature, libre-service pour les membres d'un magasin) et `/shift-claim/manage` (shifts disponibles et candidatures des magasins du gérant : approbation, rejet, proposition d'un shift attribué, retrait d'une proposition).
- La bourse : tout shift ouvert à venir (`user_id` nul dans le Core) y est d'office ; un shift attribué n'y entre que si un gérant le propose (le titulaire le garde jusqu'à l'approbation d'un candidat). Un employé ne candidate pas à son propre shift, ni à un shift d'un magasin dont il n'est pas membre actif, ni un jour de congé approuvé.
- Attribution par le Core : l'approbation appelle `ShiftReplacer` du contrat. Le shift ouvert ou cédé part à la corbeille (`replaced_by_id`) et un nouveau shift de même créneau appartient au candidat. Garde-fous du planning : congé bloquant ; chevauchement, indisponibilité, durée en avertissements à confirmer (fenêtre de confirmation, ou `409 shift_warnings` puis `force` côté API). Le gérant a besoin de `open_shifts.approve` et de `shifts.update` + `shifts.create`.
- Cas concurrents : candidature et shift relus sous verrou dans une transaction ; une seule candidature l'emporte, les autres sont rejetées dans la même transaction (rien n'est écrit si le Core demande une confirmation) ; un shift supprimé, attribué ou remplacé entre-temps refuse l'approbation.
- Suivi du planning du Core (événements) : un shift qui devient ouvert est annoncé aux membres du magasin ; un shift supprimé, remplacé ou réaffecté perd ses candidatures en attente (leurs auteurs sont prévenus) et sa proposition.
- Permissions `open_shifts.view`, `open_shifts.publish`, `open_shifts.approve`, avec la portée magasin du Core. Accordées au rôle `manager` à la première activation ; les employés n'en ont pas besoin pour candidater.
- Fonctionnalité de magasin `open_shifts`.
- Intégration au Core par le contrat : `PendingRequestProvider` (page « Demandes » et compteur).
- Notifications : `open_shift_published`, `shift_claim_submitted` (aux approbateurs), `shift_claim_approved` et `shift_claim_rejected` (e-mail), `shift_claim_withdrawn` ; l'auteur n'est jamais notifié ; lues automatiquement quand la bourse est affichée.
- Journal : `shift_claim.created`, `.withdrawn`, `.approved`, `.rejected`, `.published`, `.unpublished`.
- API mobile : `/api/v1/shift-claim` (voir `docs/API-V1.md`).
- Données : tables `shift_claims` et `shift_claim_offers`. La note est effacée à l'anonymisation du compte ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Exige `kintai/contracts` ^1.3.

Tests : `php artisan test --testsuite=Bundles`.
