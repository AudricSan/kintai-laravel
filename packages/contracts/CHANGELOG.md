# Journal des changements du contrat

Versionnement sémantique, voir le README.

## 1.5.0

### Ajouté
- `Employees\AccountActivation` (service du Core) : `deactivate($userId, $actorId = null)` et `reactivate($userId)`, qui renvoient si l'état
  a changé. Le Core applique ses garde-fous (droit `employees.update` sur l'employé, pas soi-même sauf Owner, jamais le dernier Owner
  actif) et révoque sessions et jetons d'un compte désactivé ; sans acteur (tâche planifiée), seule la règle du dernier Owner joue.
  Un refus lève une `AuthorizationException`. Ajout compatible : seul le Core implémente l'interface.
- `Employees\EmployeeActionRegistry` (service du Core), `EmployeeAction` et l'aide `BundleServiceProvider::employeeActions()` : un bundle
  ajoute une action (lien vers une route nommée, qui reçoit `?user=<id>`) sur chaque ligne de la liste des employés et sur la fiche,
  visible selon `visible(Authenticatable $viewer, int $employeeId)`. Sans effet tant que le bundle n'est pas activé.

## 1.4.0

### Ajouté
- `Backups\BackupFileRegistry` (service du Core) et l'aide `BundleServiceProvider::backupFiles($directory)` : un bundle déclare un
  sous-dossier du disque privé `local` (fichiers image à plat) que le Core inclut dans les sauvegardes (`files/<slug>/` dans l'archive,
  mêmes contrôles stricts que pour les avatars) et remet en place à la restauration. Valable bundle désactivé : ses données restent
  sauvegardées. Ajout compatible : seul le Core implémente l'interface.
- `NavigationItem` : argument optionnel `badge` (`Closure(Authenticatable): int`) et `badgeFor()` : pastille de compteur à côté de
  l'entrée du menu (menu latéral et barre mobile), absente à zéro.

## 1.3.0

### Ajouté
- `Shifts\ShiftExchanger` (service du Core) : échange atomique de deux shifts entre deux employés. Chacun est remplacé par un nouveau
  shift de même créneau qui appartient à l'autre (voir `ShiftReplacer`) ; les garde-fous du planning s'appliquent aux deux affectations
  à la fois, en tenant compte de l'échange. Ajout compatible : seul le Core implémente l'interface.
- `Shifts\ShiftWarnings` : interface (étend `Throwable`) de l'exception d'avertissements du planning que lèvent `ShiftReplacer` et
  `ShiftExchanger` tant que `$force` est faux. Un bundle l'attrape pour demander une confirmation, sans connaître la classe du Core.

## 1.2.0

### Ajouté
- `permissions($category, $actions, $defaults)` : droits par défaut des rôles `manager` et `employee`, accordés par le Core à la
  première activation du bundle (`BundleServiceProvider::defaultPermissions()`, `DEFAULT_ROLES`).
- API des bundles : `routes/api.php` chargé sous `/api/v1/<slug>` (noms `api.v1.<slug>.*`) avec la pile de l'API du Core
  (`BundleServiceProvider::API_MIDDLEWARE`), `Api\ApiController` (base de contrôleur) et `Api\ApiException` (erreur avec code
  stable, déjà rendue par le Core).
- Le contrat requiert en plus `illuminate/auth`, `illuminate/http`, `illuminate/routing` et `symfony/http-kernel` (déjà présents dans le Core).

## 1.1.0

### Ajouté
- `Stores\ColleagueDirectory` (service du Core) et l'objet valeur `Stores\Colleague` : les collègues d'un utilisateur (membres
  actifs des magasins en commun, l'Owner voit tous les magasins) avec les seules coordonnées que le Core l'autorise à voir
  (partagées par le collègue, ou droit `employees.view` sur un magasin commun). Un bundle d'annuaire ne lit plus les
  tables d'identité ni les fiches RH. Ajout compatible : seul le Core implémente l'interface.

- `Notifications\Notifier` (service du Core) : envoyer une notification d'un type déclaré (cloche, e-mail et push selon les
  préférences du destinataire) et marquer comme lues celles qui désignent un objet vu. Ajout compatible : seul le Core
  l'implémente.

## 1.0.0

Première version : registres, objets valeur, interfaces, événements, base des bundles, versionnement.
