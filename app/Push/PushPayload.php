<?php

namespace App\Push;

use App\Models\User;
use App\Notifications\KintaiNotification;
use App\Notifications\NotificationLink;
use App\Notifications\NotificationPreferences;
use App\Notifications\NotificationPresenter;

/**
 * Charge utile commune des canaux push (Web Push, FCM) : titre et corps dans la langue courante (celle du
 * destinataire pendant l'envoi d'une notification), lien interne, type, objet désigné, identifiant de la
 * notification (pour la marquer lue à l'ouverture) et `quiet` ("1" pendant les heures silencieuses : affichage
 * sans son ni vibration).
 */
final class PushPayload
{
    /**
     * @param  array<string, string>  $data  Données lues par le service worker et l'application mobile.
     */
    private function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
    ) {}

    public static function for(KintaiNotification $notification, User $user): self
    {
        $text = NotificationPresenter::present($notification->type, $notification->toDatabase($user));

        return new self($text['title'], $text['body'], [
            'type' => $notification->type,
            'link' => NotificationLink::internal($notification->link) ?? '',
            'reference_id' => $notification->referenceId !== null ? (string) $notification->referenceId : '',
            'notification_id' => (string) $notification->id,
            'quiet' => app(NotificationPreferences::class)->isQuietNow($user) ? '1' : '0',
        ]);
    }

    /**
     * Charge utile JSON plate lue par le service worker.
     */
    public function toJson(): string
    {
        return json_encode(['title' => $this->title, 'body' => $this->body] + $this->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function isQuiet(): bool
    {
        return $this->data['quiet'] === '1';
    }
}
