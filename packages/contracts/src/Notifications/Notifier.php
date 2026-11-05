<?php

namespace Kintai\Contracts\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Service du Core : envoie une notification d'un type déclaré dans `NotificationTypeRegistry` (cloche toujours, e-mail
 * et push selon les préférences du destinataire, heures silencieuses comprises) et marque comme lues celles qui
 * désignent un objet que l'utilisateur vient de voir.
 *
 *     app(Notifier::class)->notify($userIds, 'notebook_entry_created', 'New note from :author', ['author' => $name], '/notebook', $entryId);
 */
interface Notifier
{
    /**
     * Les comptes inactifs ou introuvables sont ignorés, un échec d'envoi est signalé sans lever d'exception.
     *
     * @param  int|list<int>  $userIds  Destinataires.
     * @param  string  $type  Clé d'un type enregistré.
     * @param  string  $body  Clé de traduction du corps (traduit à la lecture, dans la langue du lecteur).
     * @param  array<string, scalar|null>  $params  Paramètres du corps.
     * @param  string|null  $link  Chemin interne ouvert depuis la notification (tout autre lien est écarté).
     * @param  int|null  $referenceId  Objet désigné, de la nature `referenceType` du type.
     */
    public function notify(int|array $userIds, string $type, string $body, array $params = [], ?string $link = null, ?int $referenceId = null): void;

    /**
     * Marque comme lues les notifications non lues de l'utilisateur qui désignent ces objets.
     *
     * @param  int|list<int>  $referenceId
     */
    public function markRead(Authenticatable $user, string $referenceType, int|array $referenceId): void;
}
