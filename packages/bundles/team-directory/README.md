# Annuaire des collègues (`kintai/bundle-team-directory`)

Annuaire des collègues des magasins que l'on partage, fiche de chacun et "profil d'équipe" (bio, compétences, langues parlées, loisirs) écrit par chaque employé.

- Écrans : `/team-directory` (liste, recherche, filtre par magasin), `/team-directory/{id}` (fiche), `/team-directory/profile` (mon profil d'équipe, dans les réglages personnels).
- Libre-service : aucune permission à attribuer. L'Owner voit les membres de tous les magasins.
- Fonctionnalité de magasin `team-directory` (active par défaut) : un magasin qui la désactive n'apparaît pas dans l'annuaire.
- Visibilité : le bundle ne lit jamais les tables d'identité, il passe par `Kintai\Contracts\Stores\ColleagueDirectory` (contrat 1.1) qui applique les règles du Core : membres actifs des magasins communs, comptes actifs et non anonymisés ; e-mail et téléphones seulement si le collègue les partage (réglages du profil), ou si le demandeur a `employees.view` sur un magasin commun ; jamais l'adresse ni les autres données RH.
- Données : table `team_directory_profiles` (une ligne par employé qui a écrit son profil ; sans ligne, profil vide et visible). Effacée à l'anonymisation du compte ; désactiver le bundle la conserve.
- Journal : `team-directory.profile_updated` (champs modifiés, jamais le texte).
- Traductions : `lang/fr.json`, `lang/ja.json` (anglais = clés).

Tests : `php artisan test --testsuite=Bundles`.
