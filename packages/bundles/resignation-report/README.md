# Rapports de démission (`kintai/bundle-resignation-report`)

Le rapport de départ d'un employé dans un magasin : numéro et nom, date de démission, motif, préavis, remarques et responsable, avec aperçu et PDF. Tout son contenu est personnel : chaque action a sa permission, aucun droit n'est implicite.

- Écrans : `/resignation-report` (liste inter-magasins : filtres magasin, année et mois de démission, responsable), `/resignation-report/create` (accepte `?store=` et `?user=` pour préremplir) et `/{id}/edit` (formulaire), `/{id}` (consultation), `/{id}/pdf` (aperçu) et `/{id}/pdf/download`.
- Permissions, une par action, avec la portée magasin du Core :
  - `resignation_reports.view` : lister, ouvrir et obtenir le PDF ;
  - `resignation_reports.create` : créer ;
  - `resignation_reports.update` : modifier (en plus de `view`) ;
  - `resignation_reports.delete` : supprimer, définitivement (en plus de `view`).
- Droits par défaut, accordés à la première activation au seul rôle `manager` : les quatre. Aucun droit par défaut pour `employee`. Pas de fonctionnalité de magasin.
- Chaque route porte `can:<permission>` ; chaque composant et contrôleur revérifie la permission sur le magasin du rapport. Un rapport hors des magasins où l'on a `view` est introuvable (404).
- Règles : le magasin d'un rapport ne change plus après la création ; l'employé lié, facultatif, doit être membre du magasin (le choisir reprend son numéro et son nom). Un rapport ne désactive, ne réactive et ne supprime aucun compte : cela se fait depuis la fiche de l'employé.
- Journal : `resignation_report.created`, `.updated`, `.deleted`, `.viewed`, avec des identifiants et des noms de champs modifiés, jamais une donnée personnelle. PDF généré à la volée (mPDF), jamais stocké, réponses `Cache-Control: no-store, private`.
- Données : table `resignation_reports`. À l'anonymisation d'un employé (`UserAnonymized`), ses rapports (motif, préavis…) sont supprimés ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
