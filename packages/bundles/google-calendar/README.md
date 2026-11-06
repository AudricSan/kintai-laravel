# Google Calendar (`kintai/bundle-google-calendar`)

Les shifts et les congés approuvés de chaque employé apparaissent dans un agenda dédié que Kintai crée dans son compte
Google, et s'y mettent à jour d'eux-mêmes. La synchronisation va de Kintai vers Google uniquement : modifier un événement
dans Google ne change pas le planning. Tout le code Google vit dans ce bundle ; le Core et le contrat n'en contiennent aucun.

- Écrans :
  - `/google-calendar` : page personnelle de chacun (« Google Calendar » dans les réglages personnels, proposée quand l'Owner a
    activé la fonction ou quand la personne est déjà liée) : lier, choisir le nom de l'agenda (nom de l'organisation ou d'un de
    ses magasins), resynchroniser, délier (avec l'option de supprimer aussi les événements) ;
  - `/google-calendar/settings` : réglages de l'instance, **Owner seulement** (`manage-settings`, menu « Système ») ;
  - `/google-calendar/callback` : retour de Google (GET, limité à 20 par minute).
- Pas de permission à attribuer : libre-service, comme le profil. Chacun ne lie et ne délie que son propre agenda.
- Mise en place (Owner) : créer un projet dans la console Google Cloud (API Google Calendar activée, écran de consentement
  publié, client OAuth de type « Application Web »), copier l'**URI de redirection** affichée dans les réglages
  (`<URL publique>/google-calendar/callback`, voir « URL publique » des réglages de l'instance) dans les « URI de
  redirection autorisées » du client, puis saisir l'identifiant et le secret client et activer la fonction. Le bouton
  « Tester les identifiants » vérifie le couple auprès de Google. Les réglages sont stockés dans les réglages de l'instance
  (`google_calendar.enabled`, `.client_id`, `.client_secret`) ; le secret est chiffré en base et jamais renvoyé au navigateur.
- OAuth : code d'autorisation + PKCE (S256), périmètre `calendar.app.created openid email` (Kintai n'accède qu'à l'agenda
  qu'il crée, jamais au reste de l'agenda de la personne). Le `state` (anti-CSRF) et le vérificateur PKCE restent dans la
  session de la personne, valables 10 minutes et à usage unique. Le jeton de rafraîchissement est chiffré (cast
  `encrypted`), masqué (`$hidden`) et ne figure ni dans le journal ni dans les messages d'erreur.
- Synchronisation, en deux filets :
  - temps réel : les événements `ShiftCreated`, `ShiftUpdated`, `ShiftDeleted`, `ShiftRestored` et `ShiftReplaced` mettent la
    source en file pour l'employé lié (et l'ancien employé quand le shift change de main) ; aucun appel à Google pendant la
    requête de l'utilisateur ;
  - réconciliation : la tâche `google-calendar:sync` (planificateur de Laravel ou `/cron/run`, chaque minute, sans
    chevauchement) rejoue la file puis, toutes les 6 heures par liaison, remet en file les shifts de la fenêtre (−7 à +90 jours),
    les congés approuvés (contrat `TimeOffProvider::approvedFor`, lu pour chaque magasin de l'employé) et les événements
    orphelins. Elle rattrape ainsi une écriture oubliée, un événement supprimé à la main dans Google et tout changement de
    congé (le bundle ne reçoit pas d'événement de congé).
  - la file ne garde que la source : l'opération (créer, modifier, supprimer, rien) se déduit de l'état réel au traitement et de
    l'empreinte SHA-256 du dernier contenu envoyé. Panne passagère (réseau, 429, 5xx) : nouvel essai après 1 min, 2, 4… (6 h au
    plus), abandon après 8 essais ; refus définitif : l'élément est abandonné et l'erreur reste visible sur la page de la personne.
- Révocation : si Google refuse le jeton (accès retiré, mot de passe changé, projet resté en mode « Test » plus de 7 jours), la
  liaison passe à « Accès perdu », la file est vidée et la personne reçoit la notification `google_calendar_revoked` ; elle
  reconnecte son compte depuis sa page.
- Événements : titre « type de shift — magasin », lieu = adresse du magasin, pause et notes, lien vers le jour dans Kintai,
  couleur propre au magasin (celle des congés est réservée), dans le fuseau de l'instance et la langue de l'employé (profil,
  sinon magasin, sinon instance). Aucune donnée de salaire.
- Journal : `google_calendar.connected`, `.disconnected`, `.revoked`, `.settings_updated`, avec des identifiants et des
  indicateurs, jamais d'adresse, de jeton, d'identifiant client ni de secret.
- Données : tables `google_calendar_connections`, `google_calendar_event_links` et `google_calendar_sync_queue`. À
  l'anonymisation d'un compte (`UserAnonymized`), le jeton est révoqué chez Google et la liaison, ses liens et sa file sont
  supprimés (les événements restent dans l'agenda de la personne). Désactiver le bundle conserve tout et arrête routes, vues,
  écouteurs et tâche planifiée.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Tests : `php artisan test --testsuite=Bundles` (Google est toujours simulé par `Http::fake()`).
