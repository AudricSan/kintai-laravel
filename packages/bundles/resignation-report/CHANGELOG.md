# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `resignation-report` de l'ancien framework : pages Livewire + Flux, droits dédiés par action avec portée magasin et droits par défaut du rôle `manager`, journal (`ActivityRecorder`), PDF mPDF.

Écarts :
- Permissions `resignation_reports.view/create/update/delete` (mêmes noms qu'avant) ; modifier et supprimer exigent `view` en plus ; portée magasin du Core.
- Désactivation du compte à la date de départ (démission ou licenciement, une seule date), via le contrat `AccountActivation` (contrat 1.5, `requiredContract` `^1.5`) : colonne `deactivated_at` (le rapport n'est appliqué qu'une fois), application à l'enregistrement et par la commande planifiée quotidienne `kintai:resignation-deactivate`, réactivation par report de date, par changement d'employé, par suppression du rapport ou par l'action « Réactiver le compte ». Journal `resignation_report.deactivated/reactivated/deactivation_skipped` (identifiants seulement).
- Lier un rapport à un compte exige `employees.update` sur l'employé (portée magasin) en plus des droits du bundle ; les garde-fous (soi-même, dernier Owner) sont ceux du Core : refus de la saisie = erreur de validation, refus de la tâche planifiée = ignoré et journalisé. Le garde-fou « pas un autre gérant sauf Owner » de l'ancienne version n'existe pas dans le Core et n'est pas reproduit.
- Le lien « Licencier » revient par le point d'extension `EmployeeAction` du contrat 1.5 (liste et fiche des employés, ouvre le formulaire prérempli avec `?user=`).
- Non repris : la suppression définitive avec anonymisation, et les exports JSON/PDF multi-magasins.
- Le magasin d'un rapport ne se modifie plus après sa création ; l'employé lié est refusé s'il n'est pas membre du magasin. Le responsable est un champ libre (prérempli avec l'utilisateur connecté), filtrable par recherche partielle.
- Aucune donnée personnelle dans le journal (l'ancienne version y mettait le nom de l'employé) ; réponses PDF `no-store` ; pas de script en ligne (le préremplissage à la sélection de l'employé se fait côté serveur).
- Nouveau : à l'anonymisation d'un employé, ses rapports (motif, préavis…) sont supprimés.
- La table repart du schéma natif (clés étrangères, `nullOnDelete` pour l'employé et l'auteur) ; aucune reprise des données de l'ancienne version.
