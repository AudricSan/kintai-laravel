# Rapports d'embauche (`kintai/bundle-hiring-report`)

La fiche d'embauche d'un employé dans un magasin : identité, coordonnées, garant et emploi, avec aperçu et PDF. Elle est créée automatiquement à la création de l'employé. Tout son contenu est personnel : chaque action a sa permission, aucun droit n'est implicite.

- Écrans : `/hiring-report` (liste inter-magasins : filtres magasin, année et mois d'embauche), `/hiring-report/create` et `/{id}/edit` (formulaire), `/{id}` (consultation), `/{id}/pdf` (aperçu) et `/{id}/pdf/download`.
- Permissions, une par action, avec la portée magasin du Core :
  - `hiring_reports.view` : lister, ouvrir et obtenir le PDF ;
  - `hiring_reports.create` : créer ;
  - `hiring_reports.update` : modifier (en plus de `view`) ;
  - `hiring_reports.delete` : supprimer, définitivement (en plus de `view`).
- Droits par défaut, accordés à la première activation au seul rôle `manager` : les quatre. Aucun droit par défaut pour `employee`. Pas de fonctionnalité de magasin.
- Chaque route porte `can:<permission>` ; chaque composant et contrôleur revérifie la permission sur le magasin du rapport. Un rapport hors des magasins où l'on a `view` est introuvable (404).
- Création automatique : à l'événement `EmployeeCreated` (émis une fois l'employé rattaché à son magasin), un rapport par magasin de l'employé est créé à partir de sa fiche (`EmployeeProfile`, furigana, code employé, e-mail, nom affiché) ; date d'embauche = aujourd'hui, « embauché par » et auteur = l'utilisateur connecté s'il y en a un. Un employé sans magasin n'a pas de rapport ; jamais de doublon (employé + magasin). Un rapport créé à la main peut aussi ne pas être lié à un compte.
- Règles : le magasin d'un rapport ne change plus après la création ; l'employé lié doit être membre du magasin et n'avoir qu'un rapport dans ce magasin.
- Journal : `hiring_report.created`, `.updated`, `.deleted`, `.viewed`, avec des identifiants et des noms de champs modifiés, jamais une donnée personnelle. PDF généré à la volée (mPDF), jamais stocké, réponses `Cache-Control: no-store, private`.
- Données : table `hiring_reports`. À l'anonymisation d'un employé (`UserAnonymized`), ses rapports sont supprimés ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
