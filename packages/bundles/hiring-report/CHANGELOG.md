# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `hiring-report` de l'ancien framework : pages Livewire + Flux, droits dédiés par action avec portée magasin et droits par défaut du rôle `manager`, journal (`ActivityRecorder`), PDF mPDF.

Écarts :
- Permissions propres au bundle `hiring_reports.view/create/update/delete` à la place des droits d'administration de rapports du Core ; modifier et supprimer exigent `view` en plus.
- Création automatique depuis l'événement `EmployeeCreated` (qui ne porte que l'identifiant de l'employé) : les magasins et la fiche sont relus en base ; un rapport par magasin, jamais de doublon (employé + magasin), aucun rapport sans magasin. L'ancienne version ne créait un rapport que pour le magasin de l'événement.
- Le magasin d'un rapport ne se modifie plus après sa création ; l'employé lié est refusé s'il n'est pas membre du magasin ou s'il a déjà un rapport dans ce magasin.
- Aucune donnée personnelle dans le journal (l'ancienne version y mettait le nom de l'employé) ; réponses PDF `no-store` ; pas de script en ligne dans l'aperçu (plus de bouton « Imprimer » : impression par le navigateur).
- Nouveau : à l'anonymisation d'un employé, ses rapports sont supprimés.
- La table repart du schéma natif (clés étrangères, `nullOnDelete` pour l'employé et l'auteur) ; aucune reprise des données de l'ancienne version (outil de migration hors application).
