# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `daily-report` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), fonctionnalité de magasin `daily_reports`, notifications (`Notifier`), journal (`ActivityRecorder`), tâche planifiée `kintai:auto-validate`, API `/api/v1/daily-report/reports`.

Écarts :
- Les réglages par magasin quittent la colonne JSON `stores.daily_report_settings` du Core pour la table `daily_report_settings` du bundle ; l'interrupteur « Fonctionnalité activée » est remplacé par la fonctionnalité de magasin du Core.
- Les adresses e-mail saisies à la main (`mail_recipients`) disparaissent : les destinataires sont des employés du magasin.
- La colonne libre `data` n'existe plus (aucun écran ne l'utilisait). Un rapport supprimé l'est définitivement (l'ancienne suppression douce bloquait la recréation du jour à cause de l'unicité magasin / jour) ; son auteur supprimé le laisse sans auteur.
- Le fournisseur de ventes journalières (`SalesProvider`, rentabilité du Core et rapport de salaire de l'ancien framework) devient `KintaiContractsSalesDailySalesProvider` (contrat 1.6, implémentation `DailyReportSales`) : le bundle remplace la liaison vide du Core tant qu'il est activé, le bundle analytics lit les ventes par là (rentabilité). Les saisies cumulées (`cumulative_input`) sont ramenées à des valeurs du jour après le filtre de statut. Les trois modes de calcul restent aussi à l'affichage (cumul calculé dans la liste, badge du mode saisi).
- L'aperçu PDF est servi directement par une route ; la bascule « par jour / par magasin » est un paramètre d'URL au lieu d'un choix mémorisé par le navigateur.
- Les rapports de la liste sont limités à 500 lignes ; les jours sans rapport ne s'affichent que sur l'année écoulée.
- Nouveaux : pastille du menu, entrée dans la page « Demandes », widget « Rapports à valider », journal de la validation automatique.
