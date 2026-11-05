# Journal des changements du contrat

Versionnement sémantique, voir le README.

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
