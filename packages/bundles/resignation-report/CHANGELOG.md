# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `resignation-report` de l'ancien framework : pages Livewire + Flux, droits dédiés par action avec portée magasin et droits par défaut du rôle `manager`, journal (`ActivityRecorder`), PDF mPDF.

Écarts :
- Permissions `resignation_reports.view/create/update/delete` (mêmes noms qu'avant) ; modifier et supprimer exigent `view` en plus ; portée magasin du Core.
- Non repris : la désactivation du compte à la date de démission (tâche planifiée `resignation-deactivate`), la réactivation depuis le rapport, la suppression du compte avec le rapport, le lien « Licencier » des fiches employés, et les exports JSON/PDF multi-magasins. Le Core ne fournit ni événement ni contrat pour agir sur un compte depuis un bundle ; ces actions se font depuis la fiche de l'employé. Supprimer un rapport ne touche donc plus au compte.
- Le magasin d'un rapport ne se modifie plus après sa création ; l'employé lié est refusé s'il n'est pas membre du magasin. Le responsable est un champ libre (prérempli avec l'utilisateur connecté), filtrable par recherche partielle.
- Aucune donnée personnelle dans le journal (l'ancienne version y mettait le nom de l'employé) ; réponses PDF `no-store` ; pas de script en ligne (le préremplissage à la sélection de l'employé se fait côté serveur).
- Nouveau : à l'anonymisation d'un employé, ses rapports (motif, préavis…) sont supprimés.
- La table repart du schéma natif (clés étrangères, `nullOnDelete` pour l'employé et l'auteur) ; pas de colonne `deactivated_at` ; aucune reprise des données de l'ancienne version.
