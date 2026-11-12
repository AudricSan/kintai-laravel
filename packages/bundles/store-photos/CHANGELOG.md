# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `store-photos` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager`, réglages par `Settings`, notifications (`Notifier`), journal, fichiers sauvegardés par le Core (`backupFiles()`, contrat 1.4). Exige le contrat 1.4.

Écarts :
- les photos vivent sur le disque privé `local` et ne se lisent que par une route contrôlée (avant : dossier `storage/uploads/img` servi par le Core) ;
- le type d'une image est vérifié par son contenu et l'image est toujours ré-encodée par GD (avant : l'extension du nom décidait), avec des limites de taille, de pixels et de nombre ;
- la rétention n'archive plus les photos dans un fichier zip (l'archive n'était jamais utilisée) : le rapport est marqué archivé, les Owners sont prévenus, puis il est supprimé avec ses fichiers ; les fichiers orphelins sont nettoyés ;
- les gérants ont par défaut `photos.view`, `photos.create` et `photos.update` ; la suppression (réservée à l'Owner avant) est le droit `photos.delete` ;
- la rotation exige `photos.update` (l'ancienne vue la montrait à l'Owner seul) ;
- le libellé « semaine » (`week_label`, dérivé de la date de création) et la durée de rétention mémorisée par envoi disparaissent ; le rapport porte la date d'envoi ;
- la visionneuse plein écran est une fenêtre Flux (sans script en ligne) ;
- pas d'API : l'ancien bundle n'en avait pas.
