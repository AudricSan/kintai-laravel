# Messagerie (`kintai/bundle-messaging`)

Messagerie interne entre les membres d'un magasin : conversations à plusieurs, boîte de réception, envoyés, non lus, compteur dans le menu.

- Écrans : `/messaging` (boîte de réception, envoyés, non lus ; retirer une conversation de sa liste), `/messaging/compose` (nouveau message : magasin, un ou plusieurs collègues, objet, message) et `/messaging/threads/{id}` (la conversation, réponse, suppression de ses propres messages ; rafraîchie toutes les 5 secondes).
- Confidentialité stricte : on ne lit que les conversations dont on est participant, quel que soit son rôle. L'Owner et les gérants ne lisent pas les messages des autres (l'ancien bundle non plus) ; une conversation étrangère répond 404, comme une conversation absente.
- Destinataires : les collègues que `ColleagueDirectory` du Core montre à l'expéditeur dans un magasin où la fonctionnalité `messages` est activée (membres actifs ; l'Owner voit tous les magasins). Aucun autre compte n'est joignable, ni par les écrans ni par l'API.
- Permissions (portée magasin du Core) : `messaging.view` (ouvrir la messagerie, lister et lire ses conversations, les retirer de sa liste, supprimer ses messages, compteur de non-lus, entrée de menu et widget), `messaging.send` (répondre, ajouter un participant) et `messaging.create` (ouvrir une conversation). Sans `view`, l'entrée de menu, le badge et le widget ne s'affichent pas et toutes les surfaces (pages, actions Livewire, API) répondent 403 ; une permission ne donne jamais accès à la conversation d'autrui (404 si l'on n'y participe pas, 403 si la permission manque pour le magasin de la conversation). Droits par défaut à la première activation : les rôles `manager` et `employee` reçoivent les trois ; l'Owner (qui a tout) peut ensuite en retirer. Sur une installation où le bundle a déjà été activé, une migration du bundle donne les trois permissions (tous magasins) à tous les rôles non système, rôles personnalisés compris, pour que personne ne perde la messagerie.
- Fonctionnalité de magasin `messages` : désactivée, un magasin n'accepte plus de nouveau message ni de réponse, mais ses conversations restent lisibles.
- Lu / non lu par participant. Ouvrir une conversation la marque lue (et lit ses notifications). Une réponse remet la conversation en non lu chez les autres et la fait réapparaître chez ceux qui l'avaient retirée de leur liste.
- Retirer une conversation ne la retire que de sa liste ; elle est effacée de la base quand plus personne ne la voit. On ne supprime que ses propres messages.
- Contenu : texte brut, toujours échappé à l'affichage (les retours à la ligne sont conservés).
- Notifications : `message_received` (destinataires seulement, jamais l'auteur, dans la langue du destinataire, préférences respectées).
- Journal : `message.sent`, `message.replied`, `message.deleted`, `message.thread_hidden`, `message.thread_deleted`, `message.participant_added` — sans jamais le contenu ni l'objet des messages.
- Intégration au Core par le contrat : compteur de non-lus sur l'entrée de menu (`badge` de `NavigationItem`, contrat 1.4), widget « Messages non lus » (tableaux de bord employé et gérant).
- Anonymisation d'un compte (`UserAnonymized`) : le contenu de ses messages est effacé ; la conversation reste lisible pour les autres, l'auteur apparaît comme « Utilisateur supprimé » et ses messages comme supprimés.
- API mobile : `/api/v1/messaging` (voir `docs/API-V1.md`).
- Données : tables `message_threads`, `thread_participants`, `thread_messages` ; désactiver le bundle conserve tout.
- Traductions : `lang/fr.json`, `lang/ja.json`.

Exige `kintai/contracts` ^1.4.

Tests : `php artisan test --testsuite=Bundles`.
