# Changelog

## Unreleased

Retenues personnalisées par magasin, en plus des retenues fixes (inchangées).
- Nouvelle table `salary_report_deductions` (nom libre unique par magasin, type `percent` = % du brut de l'employé ou `fixed` = montant mensuel par employé payé, valeur, actif ; 20 au plus par magasin) et nouvelle colonne JSON `salary_reports.deduction_lines` (instantané `[{name, kind, value, amount}]`).
- Gérées dans l'écran « Réglages » (ajout, modification, activation, suppression) avec la permission `salary_reports.settings` sur le magasin ; journal `salary_report.deduction_saved` / `.deduction_deleted` sans nom ni valeur.
- Appliquées aux employés assujettis dont le brut est positif, quand les retenues du magasin sont activées ; une retenue `fixed` ne s'applique qu'aux employés payés. Elles entrent dans `total_deductions`, `net_payment` et le virement par défaut, jamais dans `other_deductions`, `withholding_tax` ni `residence_tax`.
- Le détail d'un rapport enregistré (écran, PDF) et l'API (`deduction_lines`) lisent l'instantané : modifier ou supprimer une retenue ne change pas les rapports existants ; recalculer un rapport le remplace. L'API ne permet pas de poser l'instantané.

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `salary-report` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits dédiés par action avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), fonctionnalité de magasin `salary_reports`, journal (`ActivityRecorder`), API `/api/v1/salary-report/reports`.

Écarts :
- Permissions propres au bundle `salary_reports.view/generate/export/delete/settings` à la place de `payroll.view/generate/export` du Core ; la suppression et les retenues ont leur permission (elles dépendaient de `payroll.generate` et de `stores.update`). L'export exige `view` en plus de `export`. Les gérants reçoivent les cinq à la première activation, comme ils avaient `payroll.*`.
- Les retenues du magasin (`store_deduction_settings`) et l'assujettissement des employés (`store_user.subject_to_deductions`) quittent le Core pour les tables `salary_report_settings` et `salary_report_members` ; ils se règlent dans l'écran « Réglages » du bundle, retenues désactivées et taux à 0 par défaut (l'ancienne version proposait 4,99 / 9,15 / 0,60).
- Le net et le virement sont toujours calculés (brut - retenues), même sans retenue ; l'ancienne version laissait alors 0.
- Le total des paiements n'est plus pré-rempli par les ventes journalières : le fournisseur de ventes (contrat 1.14) n'existe plus dans le Core actuel. Il se saisit à la main.
- Le nom du magasin et de l'employé ne sont plus recopiés dans le rapport (relations) ; le responsable est un compte (avec droit de générer dans le magasin, ou Owner), plus un nom libre. Les heures par employé ne sont plus un texte libre mais les minutes calculées, avec les noms relus à l'affichage.
- Le magasin, le mois et l'employé d'un rapport ne se modifient plus après sa création. Un rapport en double pour le même mois est refusé avec une erreur au lieu d'une redirection vers l'existant.
- L'export de la liste (JSON et PDF) est limité aux magasins où l'on a `view` et `export` ; un magasin hors de ce périmètre est refusé.
- Aucune donnée de salaire dans le journal ni dans les logs d'erreur de génération du PDF ; réponses `no-store`.
- Nouveau : API mobile (lecture, calcul, création, modification, suppression), effacement des textes libres à l'anonymisation.
