# Rapports journaliers (`kintai/bundle-daily-report`)

Le rapport d'un magasin pour un jour : ventes, nombre de clients, coût du personnel, pertes et notes, en trois états (brouillon, soumis, validé), avec le planning du lendemain, un PDF et un envoi par e-mail.

- Écrans : `/daily-report` (liste : filtres magasin, statut et dates, vue par jour ou par magasin, jours sans rapport, rappel, cumul), `/daily-report/create` et `/{id}/edit` (saisie), `/{id}` (détail, planning du lendemain, soumettre, valider, envoyer, supprimer, chacun confirmé dans une fenêtre), `/settings` (réglages d'un magasin), `/{id}/pdf` (aperçu) et `/{id}/pdf/download`.
- Règles : un seul rapport par magasin et par jour ; hors Owner, pas de rapport pour une date passée ; un rapport validé n'est plus modifiable ; seul un brouillon se soumet, seul un rapport soumis se valide ; la suppression est définitive. Le rapport du jour J affiche les shifts attribués du jour J+1 (membres du magasin seulement).
- Permissions `daily_reports.view`, `.create`, `.update`, `.submit`, `.approve`, `.delete`, avec la portée magasin du Core. Accordées au rôle `manager` à la première activation (sauf `delete`). Sans `view`, un membre du magasin voit ses propres rapports ; l'auteur modifie son brouillon avec `create` seul, mais ne le soumet qu'avec `submit`.
- Fonctionnalité de magasin `daily_reports` : désactivée, le magasin n'a plus d'écran ni d'API.
- Réglages par magasin (`daily_reports.update`) : destinataires (employés actifs du magasin, 20 au plus), envoi automatique à la validation, heure de validation automatique de la veille, heures de rappel et « sans rapport », colonnes de l'aperçu du planning, mode de calcul (par jour, cumulatif calculé, cumulatif saisi).
- E-mail : un message par destinataire, dans la langue de son compte, avec le PDF (mPDF) en pièce jointe ; destinataires relus à l'envoi (compte inactif, sans adresse ou sorti du magasin écartés).
- Tâche planifiée `kintai:auto-validate` (chaque heure) : valide les rapports de la veille (brouillons compris) des magasins dont c'est l'heure réglée, sans valideur ; `--date=Y-m-d` et `--all` à la main.
- Notifications : `daily_report_submitted` (valideurs du magasin, auteur exclu), `daily_report_validated` (auteur, s'il n'a pas validé lui-même), lues à l'ouverture du rapport. Menu avec pastille (rapports à valider), page « Demandes », widget « Rapports à valider ».
- Journal : `daily_report.created`, `.updated`, `.submitted`, `.validated`, `.auto_validated`, `.mail_sent`, `.deleted`, `.settings_updated`, `.pdf_exported` (jamais le contenu des notes).
- API mobile : `/api/v1/daily-report/reports` (voir `docs/API-V1.md`).
- Ventes : activé, le bundle fournit les ventes journalières au contrat (`DailySalesProvider`, `src/DailyReportSales.php`), utilisées par la rentabilité du bundle analytics ; sans lui, le Core renvoie « aucune vente ».
- Données : tables `daily_reports` et `daily_report_settings`. Les notes d'un auteur sont effacées à l'anonymisation de son compte (les chiffres restent) ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles`.
