<?php

namespace App\Push;

enum WebPushResult
{
    /** Le service de push a accepté le message. */
    case Sent;

    /** Le service de push déclare l'abonnement expiré (404 / 410) : il doit être supprimé. */
    case Expired;

    /** Échec passager ou refus : l'abonnement est conservé. */
    case Failed;
}
