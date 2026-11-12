# Carnet de notes (`kintai/bundle-notebook`)

Notes d'équipe : un message pour un magasin ou pour toute l'organisation, épinglable, avec une date d'expiration, repris sur les tableaux de bord (widgets gérant et employé) et annoncé par une notification.

- Écran : `/notebook` (liste, publication, modification, suppression avec modale de confirmation, épinglage, affichage des notes expirées).
- Permissions `notebook.view`, `notebook.create`, `notebook.manage`, avec la portée magasin du Core : on voit les notes des magasins où l'on a `notebook.view` et celles de toute l'organisation ; on publie dans les magasins où l'on a `notebook.create`, pour toute l'organisation avec `notebook.create` sans restriction de magasin ; l'auteur modifie et supprime sa note, `notebook.manage` (sur le magasin, ou sans restriction pour une note de toute l'organisation) modifie, supprime et épingle.
- Fonctionnalité de magasin `notebook` : un magasin qui la désactive n'a plus de notes dans le carnet ni dans les widgets.
- Notification `notebook_entry_created` (catégorie messages, push par défaut) aux lecteurs de la note, hors auteur ; lue automatiquement quand la note est affichée.
- Journal : `notebook.created`, `notebook.updated`, `notebook.deleted`, `notebook.pinned`.
- Données : table `notebook_entries` (`expires_on` = dernier jour affiché, fuseau de l'instance). Désactiver le bundle les conserve.
- Traductions : `lang/fr.json`, `lang/ja.json`.
- API REST pour l'application mobile : `/api/v1/notebook/entries` (liste, lecture, création, modification, suppression, épinglage), voir `docs/API-V1.md` (l'ancienne URL `/api/v1/notebook-entries` devient `/api/v1/notebook/entries`).
- Droits par défaut à la première activation : gérants `view`, `create`, `manage` ; employés `view`.

Tests : `php artisan test --testsuite=Bundles`.
