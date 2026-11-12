# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir de la synchronisation Google Agenda de l'ancien framework (qui vivait dans le Core) : client HTTP de Laravel (`Http::fake()` en test) à la place du transport curl, modèles Eloquent avec relations et factory, cast `encrypted` pour le jeton de rafraîchissement, pages Livewire + Flux, `FormRequest` pour le retour OAuth, réglages d'instance (`Settings`) avec secret chiffré, tâche du planificateur de Laravel, contrat 1.5.

Écarts :
- Le flux d'abonnement iCal (`CalendarSubscriptionLinks`) n'est pas repris : le Core a son propre flux iCal.
- Réglages de l'Owner (`/google-calendar/settings`, `manage-settings`) dans les réglages d'instance (`google_calendar.enabled`, `.client_id`, `.client_secret` chiffré) à la place de l'écran « Agendas » des réglages du Core ; le secret n'est jamais renvoyé au navigateur ; l'activité d'enregistrement ne journalise ni identifiant ni secret (l'ancienne version journalisait l'identifiant client).
- URI de redirection : `<URL publique>/google-calendar/callback` (l'ancienne était `/profile/calendar/google/callback`) : à redéclarer dans la console Google Cloud. L'URL publique vient du réglage de l'instance, sinon de la racine de la requête (comme l'ancienne version) : définir l'URL publique évite qu'un en-tête Host inattendu change l'adresse.
- Page de chacun : `/google-calendar`, section des réglages personnels, à la place de l'onglet « iCal » du profil. Plus de journal `calendar.connected/disconnected` du Core : actions `google_calendar.*` du bundle.
- Tâche `google-calendar:sync` déclarée par le bundle (planificateur de Laravel, chaque minute, sans chevauchement) à la place du job `calendar-sync` du Core ; la commande ne fait rien tant que le bundle est désactivé ou la fonction pas activée.
- Écouteurs des événements de shift du contrat (créé, modifié, supprimé, restauré, remplacé) à la place de `ShiftChangeNotifier` ; une suppression retrouve aussi la liaison par les événements déjà créés. Écouteur de `UserAnonymized` : le jeton est révoqué chez Google et la liaison, ses liens et sa file sont supprimés.
- Congés : lus par le contrat `TimeOffProvider::approvedFor` pour chaque magasin de l'employé (un congé vu dans plusieurs magasins n'est gardé qu'une fois, identifié par son `uid` stable) ; sans le bundle de congés, aucun événement de congé. Les changements de congé sont rattrapés par la réconciliation (toutes les 6 heures), pas en temps réel.
- Schéma natif : tables `google_calendar_*` avec clés étrangères (`cascadeOnDelete`), nom de l'agenda en colonnes (`name_mode`, `store_id`) plutôt qu'en JSON, source d'un lien en `source_type` + `source_key` (texte : identifiant du shift ou `uid` du congé) ; aucune reprise des données de l'ancienne version (outil de migration hors application) : les employés relient leur compte.
- Une source sans événement attendu ni lien est ignorée sans demander de jeton ni toucher à l'agenda ; le détail d'une erreur inattendue reste dans les journaux de l'application, la page de la personne affiche un message neutre.
- Les heures des événements viennent des instants du shift (fuseau de l'instance), plus d'une recomposition date + heure.
