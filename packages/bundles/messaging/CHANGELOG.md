# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `messaging` 3.0.0 de l'ancien framework : pages Livewire + Flux, destinataires par `ColleagueDirectory`, `Notifier`, journal, widget, API `/api/v1/messaging` (anciennes URL `/api/v1/messages/*`). Exige le contrat 1.4 (pastille de compteur du menu).

Écarts :
- les écrans `/admin/messages` (gérants, protégé par `messaging.view/send/delete`) et `/employee/messages` n'en font plus qu'un, `/messaging`, en libre-service : les deux écrans faisaient la même chose et le droit n'ajoutait rien (un gérant ne lisait déjà pas les messages des autres). Les permissions `messaging.*` n'existent plus ;
- les destinataires sont les collègues du magasin vus par le Core (plus la liste de tous les utilisateurs pour l'administrateur général) ; l'API refuse aussi d'ajouter un participant qui n'est pas collègue du magasin de la conversation (avant : un magasin quelconque en commun) ;
- la mise à jour en direct par SSE devient un rafraîchissement de la conversation toutes les 5 secondes (Livewire) ;
- un message a un objet de 255 caractères et un corps de 5000 au plus, vérifiés partout ; l'API crée une conversation avec son premier message ;
- une conversation de la liste d'un magasin dont la fonctionnalité est désactivée reste lisible mais n'accepte plus de réponse ;
- à l'anonymisation d'un compte, le contenu de ses messages est effacé (avant : conservé) ;
- le journal ne garde plus l'objet des messages ;
- le compteur de non-lus passe par `NavigationItem::badge` et un widget ; l'API gagne `GET /api/v1/messaging/unread`.
