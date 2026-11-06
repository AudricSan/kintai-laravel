# Rapports de démission (`kintai/bundle-resignation-report`)

Le rapport de départ d'un employé dans un magasin : numéro et nom, date de départ (démission ou licenciement : une seule date, le libellé reste « démission »), motif, préavis, remarques et responsable, avec aperçu et PDF. Lié à un compte, il le désactive à la date de départ. Tout son contenu est personnel : chaque action a sa permission, aucun droit n'est implicite.

- Écrans : `/resignation-report` (liste inter-magasins : filtres magasin, année et mois de démission, responsable), `/resignation-report/create` (accepte `?store=` et `?user=` pour préremplir) et `/{id}/edit` (formulaire), `/{id}` (consultation), `/{id}/pdf` (aperçu) et `/{id}/pdf/download`.
- Permissions, une par action, avec la portée magasin du Core :
  - `resignation_reports.view` : lister, ouvrir et obtenir le PDF ;
  - `resignation_reports.create` : créer ;
  - `resignation_reports.update` : modifier (en plus de `view`) ;
  - `resignation_reports.delete` : supprimer, définitivement (en plus de `view`).
- Droits par défaut, accordés à la première activation au seul rôle `manager` : les quatre. Aucun droit par défaut pour `employee`. Pas de fonctionnalité de magasin.
- Chaque route porte `can:<permission>` ; chaque composant et contrôleur revérifie la permission sur le magasin du rapport. Un rapport hors des magasins où l'on a `view` est introuvable (404).
- Règles : le magasin d'un rapport ne change plus après la création ; l'employé lié, facultatif, doit être membre du magasin (le choisir reprend son numéro et son nom). Action « Licencier » (`resignation-report.dismiss`, contrat `EmployeeActionRegistry`) sur la liste et la fiche des employés : visible pour qui a `resignation_reports.create` dans un magasin de l'employé, jamais sur son propre compte ; elle ouvre `/resignation-report/create?user=<id>`, dont le formulaire ne préremplit qu'un employé d'un magasin où l'on peut créer.
- Lier un rapport à un compte exige de pouvoir modifier cet employé (`employees.update` du Core, avec la portée magasin), puisque le rapport désactive ce compte.
- Désactivation du compte (contrat `AccountActivation`, depuis 1.5) : à l'enregistrement et chaque jour (commande `kintai:resignation-deactivate`, planifiée à 00:05 dans le fuseau de l'instance, sans chevauchement), un rapport lié dont la date est atteinte (ou absente) désactive le compte, une seule fois (`deactivated_at`). Un compte réactivé à la main le reste. Repousser la date dans le futur, changer l'employé lié ou supprimer un rapport appliqué réactive le compte ; l'action « Réactiver le compte » de la fiche (droit `update` + droit sur l'employé) le réactive durablement. Les garde-fous sont ceux du Core : pas soi-même (sauf Owner), jamais le dernier Owner. À la saisie, un refus est une erreur de validation et rien n'est enregistré ; la tâche planifiée ignore un refus et le journalise (`resignation_report.deactivation_skipped`).
- Journal : `resignation_report.created`, `.updated`, `.deleted`, `.viewed`, `.deactivated`, `.reactivated`, `.deactivation_skipped`, avec des identifiants et des noms de champs modifiés, jamais une donnée personnelle. PDF généré à la volée (mPDF), jamais stocké, réponses `Cache-Control: no-store, private`.
- Données : table `resignation_reports` (dont `deactivated_at`). À l'anonymisation d'un employé (`UserAnonymized`), ses rapports (motif, préavis…) sont supprimés ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
