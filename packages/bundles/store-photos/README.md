# Photos de magasin (`kintai/bundle-store-photos`)

Envoi et suivi de photos de l'espace de vente, par magasin et par jour.

- Écrans : `/store-photos` (rapports groupés par jour, filtres magasin et jour), `/store-photos/create` (nouvel envoi : magasin, photos, notes), `/store-photos/{id}` (le rapport : photos, visionneuse, rotation, suppression) et `/store-photos/settings` (rétention, Owner seulement). Les envois d'un même magasin le même jour s'ajoutent à un seul rapport (photos et notes s'accumulent).
- Droits avec la portée magasin du Core : `photos.view`, `photos.create` (magasin dont la fonctionnalité `photos` est activée), `photos.update` (rotation), `photos.delete`. Accordés au rôle `manager` à la première activation, sauf la suppression. Les réglages sont réservés à l'Owner.
- Envoi sécurisé : le type est reconnu par le **contenu** (JPEG, PNG, GIF, WebP), jamais par le nom ni par le type déclaré ; 10 Mo par photo, 20 photos et 40 Mo par envoi, 40 millions de pixels décodés au plus. Chaque image est décodée par GD puis ré-encodée (JPEG qualité 82, ou PNG si elle a de la transparence), réduite à 2000 px de côté au plus, après correction de l'orientation EXIF : les métadonnées (EXIF, GPS, commentaires) et toute donnée ajoutée après l'image disparaissent, un fichier piégé est refusé ou devient une image saine. Si une photo est refusée, rien n'est enregistré.
- Stockage privé : disque `local` (`storage/app/private/store-photos/`), jamais accessible par le web, sous des noms générés (32 hexadécimaux) ; le nom d'origine n'est gardé que pour l'affichage. Lecture par la seule route `store-photos/images/{id}` : utilisateur connecté qui a `photos.view` sur le magasin du rapport, ou membre actif de ce magasin ; sinon 404. Réponse typée par le serveur, `X-Content-Type-Options: nosniff`, cache privé. Pas de miniature (l'ancien bundle n'en avait pas) : les photos sont déjà allégées par le ré-encodage.
- Suppression : le fichier part avec l'enregistrement (photo, rapport, rétention).
- Rétention (commande planifiée `kintai:photo-retention`, chaque nuit à 02:00) : après `photos.retention_days` jours (14 par défaut) le rapport est marqué archivé et les Owners sont prévenus une fois (`photo_archived`) ; après `photos.cleanup_delay` jours de plus (7), il est supprimé définitivement avec ses fichiers. La commande supprime aussi les fichiers orphelins de plus d'un jour (magasin supprimé, base restaurée, données réinitialisées).
- Sauvegardes : le dossier des photos est déclaré au Core (`backupFiles()`, contrat 1.4) ; il est inclus dans les archives de sauvegarde (`files/store-photos/`) et restauré avec la base, **même bundle désactivé**.
- Journal : `photo.submission_created`, `photo.submission_merged`, `photo.submission_deleted`, `photo.image_rotated`, `photo.settings_updated` (jamais le contenu des notes).
- Données : tables `store_photo_submissions` et `store_photo_images` ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Exige `kintai/contracts` ^1.4.

Tests : `php artisan test --testsuite=Bundles`.
