<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Notifications\NotificationTypeRegistry;
use Symfony\Component\Mime\Email;

/**
 * Notification d'un type déclaré dans `NotificationTypeRegistry`. La cloche (canal `database`) est toujours
 * alimentée ; l'e-mail et le push (Web Push pour les navigateurs, FCM pour l'application mobile) suivent les
 * préférences de l'utilisateur. Le push d'un utilisateur en heures silencieuses part sans son (`quiet`, voir `PushPayload`).
 *
 * Le titre vient du type ; le corps est une clé de traduction avec ses paramètres, traduits à l'affichage.
 */
class KintaiNotification extends Notification
{
    /**
     * @param  string  $type  Clé d'un type enregistré.
     * @param  string  $body  Clé de traduction du corps.
     * @param  array<string, scalar|null>  $params  Paramètres du corps ; `date` ("Y-m-d") est mise en forme à l'affichage.
     * @param  string|null  $link  Chemin interne ouvert depuis la notification (tout autre lien est écarté).
     * @param  int|null  $referenceId  Objet désigné, de la nature `reference_type` du type.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $body,
        public readonly array $params = [],
        public readonly ?string $link = null,
        public readonly ?int $referenceId = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function make(string $type, string $body, array $params = [], ?string $link = null, ?int $referenceId = null): self
    {
        return new self($type, $body, $params, $link, $referenceId);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $this->mailEnabled($notifiable)) {
            $channels[] = 'mail';
        }

        // Push : seulement si le type l'autorise pour ce destinataire et qu'un appareil est joignable. Chaque
        // canal est isolé (il journalise ses échecs sans lever), les heures silencieuses sont portées par la charge utile.
        if ($notifiable instanceof User && app(NotificationPreferences::class)->isEnabled($notifiable, $this->type, NotificationType::CHANNEL_PUSH)) {
            if (WebPushChannel::isAvailableFor($notifiable)) {
                $channels[] = WebPushChannel::class;
            }

            if (FcmChannel::isAvailableFor($notifiable)) {
                $channels[] = FcmChannel::class;
            }
        }

        return $channels;
    }

    private function mailEnabled(User $user): bool
    {
        return filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false
            && app(NotificationPreferences::class)->isEnabled($user, $this->type, NotificationType::CHANNEL_MAIL);
    }

    /**
     * La colonne `type` porte la clé du type Kintai (pas le nom de la classe).
     */
    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $definition = app(NotificationTypeRegistry::class)->get($this->type);

        return [
            'body' => $this->body,
            'params' => $this->params,
            'link' => NotificationLink::internal($this->link),
            'reference_type' => $this->referenceId !== null ? $definition?->referenceType : null,
            'reference_id' => $this->referenceId,
        ];
    }

    /**
     * Rédigé dans la langue du destinataire (`User::preferredLocale()`).
     */
    public function toMail(object $notifiable): MailMessage
    {
        $text = NotificationPresenter::present($this->type, $this->toDatabase($notifiable));
        $definition = app(NotificationTypeRegistry::class)->get($this->type);
        $unsubscribe = null;

        if ($notifiable instanceof User && $definition !== null && ! $definition->internal) {
            // Lien signé sans expiration : un e-mail ancien doit pouvoir se désabonner.
            $unsubscribe = URL::signedRoute('notifications.unsubscribe', ['user' => $notifiable->id, 'type' => $this->type]);
        }

        $message = (new MailMessage)
            ->subject('['.config('app.name').'] '.$text['title'])
            ->view('mail.notification', [
                'title' => $text['title'],
                'body' => $text['body'],
                'openUrl' => route('notifications.open', ['notification' => $this->id]),
                'unsubscribeUrl' => $unsubscribe,
            ]);

        if ($unsubscribe !== null) {
            $message->withSymfonyMessage(function (Email $email) use ($unsubscribe): void {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $message;
    }
}
